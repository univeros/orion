<?php

declare(strict_types=1);

namespace Orion\Solr;

use InvalidArgumentException;
use LogicException;
use Orion\Adapter\Capability;
use Orion\Adapter\PendingTask;
use Orion\Adapter\RequiresReindex;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\IndexName;
use Orion\Query\Filter;
use Orion\Query\Hit;
use Orion\Query\Query;
use Orion\Query\ResultSet;
use Orion\Schema\ChangeKind;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Schema\LiveSchema;
use Orion\Schema\MigrationPlan;
use Orion\Support\Assert;
use Orion\Support\NullEventDispatcher;
use Orion\Write\BulkResult;
use Orion\Write\Document;
use Orion\Write\Tombstone;
use Orion\Write\WriteOutcome;
use JsonException;
use Override;
use RuntimeException;
use Solarium\Core\Client\Adapter\Curl;
use Solarium\Core\Client\Client;
use Throwable;

use function array_diff;
use function array_filter;
use function array_intersect;
use function array_is_list;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_int;
use function is_scalar;
use function is_string;
use function json_decode;
use function json_encode;
use function ltrim;
use function parse_url;
use function rtrim;
use function sprintf;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_URL_HOST;
use const PHP_URL_PATH;
use const PHP_URL_PORT;
use const PHP_URL_SCHEME;

/**
 * The Apache Solr adapter (SolrCloud 9.x and 10.x): one collection per physical index, built from Orion's configset
 * ({@see Configset}) with the declared fields added through the Schema API, behind a collection alias. Writes are
 * versioned by the outbox offset and tolerant per document; deletes leave tombstones that searches exclude.
 * `raw()` takes `callable(): array`, the request parameters (`['q' => '...', 'fq' => [...]]`); see {@see NativeScope}.
 * Hybrid fusion is left to core.
 */
final class SolrEngine implements SearchAdapter, SchemaAdapter, WriteAdapter
{
    public const string NAME = 'solr';
    public const float DEFAULT_TIMEOUT = 60.0;
    private const array CAPABILITIES = [
        Capability::Vectors, Capability::Facets, Capability::Highlighting, Capability::NativeAliases,
        Capability::CursorPagination, Capability::FunctionScore, Capability::OptimisticConcurrency,
    ];
    /** What `native('solr', ...)` may set, all on collection creation. */
    private const array COLLECTION = ['numShards', 'replicationFactor', 'nrtReplicas', 'tlogReplicas', 'pullReplicas'];

    private readonly Http $http;
    private readonly QueryCompiler $compiler;

    public function __construct(Client $client)
    {
        $this->http = new Http($client);
        $this->compiler = new QueryCompiler(self::NAME);
    }

    /**
     * An engine on Solarium's cURL adapter for a SolrCloud node (`http://host:8983/solr`), for hosts that configure
     * Orion from the environment rather than building the client themselves.
     */
    public static function connect(string $url, float $timeout = self::DEFAULT_TIMEOUT): self
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            throw new InvalidArgumentException(sprintf('%s is not a Solr URL such as http://localhost:8983/solr.', Assert::show($url)));
        }
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $port = parse_url($url, PHP_URL_PORT);
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $adapter = new Curl();
        $adapter->setTimeout((int) $timeout);
        $client = new Client($adapter, new NullEventDispatcher(), ['endpoint' => ['solr' => [
            'scheme' => is_string($scheme) ? $scheme : 'http',
            'host' => $host,
            'port' => is_int($port) ? $port : 8983,
            'path' => '/',
            'context' => $path === '' ? 'solr' : ltrim($path, '/'),
        ]]]);

        return new self($client);
    }

    #[Override]
    public function supports(Capability $capability): bool
    {
        return in_array($capability, self::CAPABILITIES, true);
    }

    #[Override]
    public function search(IndexName $index, Query $query): ResultSet
    {
        $types = $this->compiler->needsTypes($query) ? $this->types($index) : [];

        return $this->results(Http::ok($this->http->form($index->value . '/select', $this->compiler->compile($query, $types)), 'search ' . $index->value));
    }

    #[Override]
    public function raw(IndexName $index, callable $native, Filter ...$scope): ResultSet
    {
        $params = $native();
        if (!is_array($params)) {
            throw new LogicException('A Solr native query is a callable returning request parameters.');
        }
        [$filters, $bound] = $this->compiler->scopeFilters($scope, NativeScope::prefix());

        return $this->results(Http::ok($this->http->form($index->value . '/select', NativeScope::request($params, $filters, $bound)), 'search ' . $index->value));
    }

    /**
     * Creates the collection from Orion's configset and adds the declared fields. A collection that exists, whether a
     * concurrent `ensure()` built it or a crash left it without its fields, is completed with the declared fields it
     * lacks; nothing else about it changes. A collection this call created and could not complete is dropped again.
     */
    #[Override]
    public function ensure(IndexSchema $schema): void
    {
        $definitions = Schema::definitions($schema->fields);
        if ($this->exists($schema->index)) {
            $this->complete($schema);

            return;
        }
        $configset = Configset::forCollection($this->http, $schema->index);
        $created = $this->http->get('admin/collections', [
            'action' => 'CREATE',
            'name' => $schema->index->value,
            'collection.configName' => $configset,
            'numShards' => '1',
            'replicationFactor' => '1',
            ...self::collectionSettings($schema),
        ]);
        if ($created[0] !== 200) {
            if (!$this->exists($schema->index)) {
                Http::ok($created, 'create the collection ' . $schema->index->value);
            }
            // Another ensure() created it meanwhile: complete it as one that already existed.
            $this->complete($schema);

            return;
        }
        try {
            $this->addFields($schema->index, $definitions);
        } catch (Throwable $failure) {
            try {
                $this->drop($schema->index);
            } catch (Throwable $cleanup) {
                throw new RuntimeException(sprintf('%s; dropping the half-built collection failed too: %s', $failure->getMessage(), $cleanup->getMessage()), 0, $failure);
            }

            throw $failure;
        }
    }

    #[Override]
    public function inspect(IndexName $index): ?LiveSchema
    {
        [$status, $fields] = $this->http->get($index->value . '/schema/fields', ['showDefaults' => 'true']);
        if ($status === 404) {
            return null;
        }
        Http::ok([$status, $fields], 'read the fields of ' . $index->value);
        $types = Http::ok($this->http->get($index->value . '/schema/fieldtypes', ['showDefaults' => 'true']), 'read the field types of ' . $index->value);

        return new LiveSchema($index, Schema::fields(self::list($fields['fields'] ?? []), self::list($types['fieldTypes'] ?? [])));
    }

    #[Override]
    public function plan(IndexSchema $desired): MigrationPlan
    {
        return MigrationPlan::between($desired, $this->inspect($desired->index));
    }

    /**
     * Solr adds fields to a live collection, analysis included, so every additive change applies in place.
     */
    #[Override]
    public function apply(MigrationPlan $plan): PendingTask
    {
        if ($plan->requiresReindex()) {
            throw RequiresReindex::for($plan->index, 'the plan has breaking changes.');
        }
        if (!$this->exists($plan->index)) {
            throw new LogicException(sprintf('The index %s does not exist; ensure it first.', $plan->index->value));
        }
        $fields = [];
        foreach ($plan->additive() as $change) {
            if ($change->kind === ChangeKind::Added && $change->after instanceof Field) {
                $fields[] = [$change->path, $change->after];
            }
        }
        $this->addChanges($plan->index, $fields);

        return PendingTask::completed();
    }

    /**
     * Deletes the collection and its configset, taking it out of any alias first, as deleting an Elasticsearch index
     * removes its aliases; a name that is no collection is left alone, but for a configset a crash left behind.
     */
    #[Override]
    public function drop(IndexName $index): void
    {
        $listed = Http::ok($this->http->get('admin/collections', ['action' => 'LIST']), 'list collections');
        if (!in_array($index->value, is_array($listed['collections'] ?? null) ? $listed['collections'] : [], true)) {
            Configset::release($this->http, $index);

            return;
        }
        foreach ($this->aliases() as $alias => $collections) {
            if (in_array($index->value, $collections, true)) {
                $rest = array_values(array_diff($collections, [$index->value]));
                Http::ok($rest === []
                    ? $this->http->get('admin/collections', ['action' => 'DELETEALIAS', 'name' => $alias])
                    : $this->http->get('admin/collections', ['action' => 'CREATEALIAS', 'name' => $alias, 'collections' => implode(',', $rest)]), 'release the alias ' . $alias);
            }
        }
        Http::ok($this->http->get('admin/collections', ['action' => 'DELETE', 'name' => $index->value]), 'delete the collection ' . $index->value);
        Configset::release($this->http, $index);
    }

    /**
     * One request, applied atomically: the alias points at the new collection, whatever it pointed at before.
     */
    #[Override]
    public function alias(IndexName $alias, IndexName $physical): void
    {
        Http::ok($this->http->get('admin/collections', ['action' => 'CREATEALIAS', 'name' => $alias->value, 'collections' => $physical->value]), 'point the alias ' . $alias->value);
    }

    #[Override]
    public function resolve(IndexName $alias): ?IndexName
    {
        $collections = $this->aliases()[$alias->value] ?? null;
        if ($collections === null) {
            return null;
        }
        if (count($collections) !== 1) {
            throw new LogicException(sprintf('The alias %s points at %d collections; Orion keeps exactly one behind an alias.', $alias->value, count($collections)));
        }

        return IndexName::of($collections[0]);
    }

    #[Override]
    public function index(IndexName $index, Document ...$docs): BulkResult
    {
        $this->assertExists($index);
        $body = [];
        $sent = [];
        $refused = [];
        foreach ($docs as $doc) {
            $flat = self::flatten($doc->fields);
            $reserved = array_values(array_intersect(array_keys($flat), Schema::INTERNAL));
            try {
                // A list is a vector here (every other field is single-valued), and no engine returns vectors in a hit.
                $source = json_encode(self::withoutLists($doc->fields), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            } catch (JsonException $invalid) {
                $refused[$doc->id] = WriteOutcome::failed($doc->id, 'The document is not JSON: ' . $invalid->getMessage());
                continue;
            }
            if ($reserved !== []) {
                $refused[$doc->id] = WriteOutcome::failed($doc->id, sprintf('The fields %s are Orion\'s own.', implode(', ', $reserved)));
                continue;
            }
            $body[] = [...$flat, 'id' => $doc->id, 'orion_version' => $doc->version, 'orion_source' => $source];
            $sent[] = $doc->id;
        }
        $outcomes = [];
        if ($body !== []) {
            foreach (UpdateResponse::outcomes($this->http->json($index->value . '/update', [], $body)[1], $sent) as $outcome) {
                $outcomes[$outcome->id] = $outcome;
            }
        }

        return BulkResult::completed(...array_map(static fn(Document $doc): WriteOutcome => $refused[$doc->id] ?? $outcomes[$doc->id], array_values($docs)));
    }

    /**
     * One request per tombstone: Solr takes a delete's version as a request parameter.
     */
    #[Override]
    public function delete(IndexName $index, Tombstone ...$tombstones): BulkResult
    {
        $this->assertExists($index);
        $outcomes = [];
        foreach ($tombstones as $tombstone) {
            $answer = $this->http->json($index->value . '/update', ['orion_delete_version' => (string) $tombstone->version], ['delete' => ['id' => $tombstone->id]]);
            $outcomes = [...$outcomes, ...UpdateResponse::outcomes($answer[1], [$tombstone->id])];
        }

        return BulkResult::completed(...$outcomes);
    }

    /**
     * A commit: synchronous, so the written documents are searchable when it returns.
     */
    #[Override]
    public function flush(IndexName $index, bool $await = false): void
    {
        Http::ok($this->http->json($index->value . '/update', ['commit' => 'true'], []), 'commit ' . $index->value);
    }

    /**
     * Asks the cluster each time: another process may create or drop the collection between two calls.
     *
     * @phpstan-impure
     * @throws RuntimeException when Solr cannot say: an outage is not a missing index
     */
    private function exists(IndexName $index): bool
    {
        $answer = $this->http->get($index->value . '/schema/uniquekey');

        return match ($answer[0]) {
            200 => true,
            404 => false,
            default => Http::ok($answer, 'tell whether ' . $index->value . ' exists') !== [],
        };
    }

    /**
     * Adds the declared fields an existing collection lacks.
     */
    private function complete(IndexSchema $schema): void
    {
        $fields = [];
        foreach (MigrationPlan::between($schema, $this->inspect($schema->index))->additive() as $change) {
            if ($change->kind === ChangeKind::Added && $change->after instanceof Field) {
                $fields[] = [$change->path, $change->after];
            }
        }
        $this->addChanges($schema->index, $fields);
    }

    /**
     * Writing to a missing collection must fail rather than reach some other handler.
     */
    private function assertExists(IndexName $index): void
    {
        if (!$this->exists($index)) {
            throw new InvalidArgumentException(sprintf('The index %s does not exist; ensure it first.', $index->value));
        }
    }

    /**
     * @param list<array{string, Field}> $added each added field with its (dotted) path
     */
    private function addChanges(IndexName $index, array $added): void
    {
        $types = [];
        $fields = [];
        foreach ($added as [$path, $field]) {
            $parent = implode('.', array_slice(explode('.', $path), 0, -1));
            $definitions = Schema::definitions([$field], $parent === '' ? '' : $parent . '.');
            $types = [...$types, ...$definitions['types']];
            $fields = [...$fields, ...$definitions['fields']];
        }
        if ($fields !== []) {
            $this->addFields($index, ['types' => $types, 'fields' => $fields]);
        }
    }

    /**
     * @param array{types: list<array{name: string, definition: array<string, mixed>}>, fields: list<array<string, mixed>>} $definitions
     */
    private function addFields(IndexName $index, array $definitions): void
    {
        $existing = self::list(Http::ok($this->http->get($index->value . '/schema/fieldtypes'), 'read the field types of ' . $index->value)['fieldTypes'] ?? []);
        $names = array_map(static fn(array $type): string => is_scalar($type['name'] ?? null) ? (string) $type['name'] : '', $existing);
        $types = [];
        foreach ($definitions['types'] as $type) {
            if (!in_array($type['name'], $names, true)) {
                $types[$type['name']] = ['name' => $type['name'], ...$type['definition']];
            }
        }
        $commands = array_filter(['add-field-type' => array_values($types), 'add-field' => $definitions['fields']], static fn(array $list): bool => $list !== []);
        if ($commands !== []) {
            Http::ok($this->http->json($index->value . '/schema', [], $commands), 'add fields to ' . $index->value);
        }
    }

    /**
     * The index's field types by dotted name, for compiling range bounds.
     *
     * @return array<string, FieldType>
     */
    private function types(IndexName $index): array
    {
        $types = [];
        $walk = static function (array $fields, string $prefix) use (&$walk, &$types): void {
            foreach ($fields as $field) {
                if ($field instanceof Field) {
                    $field->type === FieldType::Object ? $walk($field->fields, $prefix . $field->name . '.') : $types[$prefix . $field->name] = $field->type;
                }
            }
        };
        $walk($this->inspect($index)->fields ?? [], '');

        return $types;
    }

    /**
     * @return array<string, list<string>>
     */
    private function aliases(): array
    {
        $listed = Http::ok($this->http->get('admin/collections', ['action' => 'LISTALIASES']), 'list aliases');
        $aliases = [];
        foreach (is_array($listed['aliases'] ?? null) ? $listed['aliases'] : [] as $alias => $collections) {
            $aliases[(string) $alias] = array_values(array_filter(explode(',', is_scalar($collections) ? (string) $collections : ''), static fn(string $name): bool => $name !== ''));
        }

        return $aliases;
    }

    /**
     * @return array<string, string>
     */
    private static function collectionSettings(IndexSchema $schema): array
    {
        $native = $schema->native[self::NAME] ?? [];
        $settings = [];
        foreach ($native as $key => $value) {
            if ($key !== 'collection' || !is_array($value)) {
                throw new InvalidArgumentException(sprintf('Native Solr settings take "collection" (%s) only, not %s.', implode(', ', self::COLLECTION), Assert::show((string) $key)));
            }
            foreach ($value as $name => $setting) {
                if (!in_array($name, self::COLLECTION, true) || !is_numeric($setting)) {
                    throw new InvalidArgumentException(sprintf('Native Solr collection settings are %s, as numbers; %s is not one.', implode(', ', self::COLLECTION), Assert::show((string) $name)));
                }
                $settings[(string) $name] = (string) $setting;
            }
        }

        return $settings;
    }

    /**
     * @param array<array-key, mixed> $fields
     * @return array<string, mixed>
     */
    private static function withoutLists(array $fields): array
    {
        $kept = [];
        foreach ($fields as $name => $value) {
            if (is_array($value) && array_is_list($value)) {
                continue;
            }
            $kept[(string) $name] = is_array($value) ? self::withoutLists($value) : $value;
        }

        return $kept;
    }

    /**
     * Object fields as Solr's dotted fields; vectors and other lists stay values.
     *
     * @param array<array-key, mixed> $fields
     * @return array<string, mixed>
     */
    private static function flatten(array $fields, string $prefix = ''): array
    {
        $flat = [];
        foreach ($fields as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (is_array($value) && $value !== [] && !array_is_list($value)) {
                $flat = array_merge($flat, self::flatten($value, $prefix . $name . '.'));
                continue;
            }
            $flat[$prefix . $name] = $value;
        }

        return $flat;
    }

    /**
     * @param array<array-key, mixed> $response
     */
    private function results(array $response): ResultSet
    {
        $body = is_array($response['response'] ?? null) ? $response['response'] : [];
        $highlighting = is_array($response['highlighting'] ?? null) ? $response['highlighting'] : [];
        $hits = [];
        foreach (self::list($body['docs'] ?? []) as $doc) {
            $id = is_scalar($doc['id'] ?? null) ? (string) $doc['id'] : '';
            $source = is_scalar($doc['orion_source'] ?? null) ? json_decode((string) $doc['orion_source'], true) : null;
            $highlights = [];
            foreach (is_array($highlighting[$id] ?? null) ? $highlighting[$id] : [] as $field => $fragments) {
                $fragments = array_values(array_map(static fn($fragment): string => is_scalar($fragment) ? (string) $fragment : '', (array) $fragments));
                if ($fragments !== []) {
                    $highlights[(string) $field] = $fragments;
                }
            }
            $hits[] = new Hit($id, is_numeric($doc['score'] ?? null) ? (float) $doc['score'] : 0.0, is_array($source) ? $source : [], $highlights);
        }
        $facets = [];
        $fields = is_array($response['facet_counts']['facet_fields'] ?? null) ? $response['facet_counts']['facet_fields'] : [];
        foreach ($fields as $field => $pairs) {
            $pairs = array_values((array) $pairs);
            $counts = [];
            for ($i = 0; $i + 1 < count($pairs); $i += 2) {
                $counts[is_scalar($pairs[$i]) ? (string) $pairs[$i] : ''] = is_numeric($pairs[$i + 1]) ? (int) $pairs[$i + 1] : 0;
            }
            $facets[(string) $field] = $counts;
        }
        $header = is_array($response['responseHeader'] ?? null) ? $response['responseHeader'] : [];

        return new ResultSet(
            $hits,
            is_numeric($body['numFound'] ?? null) ? (int) $body['numFound'] : count($hits),
            ($body['numFoundExact'] ?? true) === false,
            $facets,
            is_numeric($header['QTime'] ?? null) ? (float) $header['QTime'] : 0.0,
        );
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }
}
