<?php

declare(strict_types=1);

namespace Orion\Psr15;

use InvalidArgumentException;
use Orion\Manifest\Endpoint;
use Orion\Query\Filter;
use Orion\Query\Query;
use Orion\Query\Searcher;
use Orion\Query\Sort;
use Orion\Query\VectorClause;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\IndexSchema;
use Orion\Support\Assert;
use Orion\Support\Input;
use JsonException;
use Psr\Http\Message\ServerRequestInterface;

use function array_is_list;
use function count;
use function explode;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function min;
use function preg_match;
use function preg_split;
use function sprintf;
use function strlen;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * A request to a declared endpoint read into the neutral query (v1 §12). A GET endpoint reads its query string, a
 * POST endpoint a JSON body, with the same keys: `q` (text over the endpoint's query fields), `filter` (by field:
 * one value for a term, a list for terms, `gt`/`gte`/`lt`/`lte` bounds for a range), `sort` (`field` or `-field`,
 * several comma-separated or as a list), `page`, `size` (at most the endpoint's page size) and, on a hybrid
 * endpoint, `vector`. A request may only name what the endpoint declares; values are read as the schema types the
 * field, and the searcher checks the query against the schema again before any engine sees it.
 */
final readonly class SearchRequest
{
    public const array KEYS = ['q', 'filter', 'sort', 'page', 'size', 'vector'];
    private const array BOUNDS = ['gt', 'gte', 'lt', 'lte'];
    private const int MAX_BODY_BYTES = 1048576;
    /** Words in `q`: every engine makes one clause per word and Solr refuses too many. */
    public const int MAX_TERMS = 64;

    private function __construct(public Query $query)
    {
    }

    /**
     * @throws InvalidArgumentException naming the parameter that does not fit
     */
    public static function from(ServerRequestInterface $request, Endpoint $endpoint, IndexSchema $schema): self
    {
        $params = $endpoint->method === 'POST' ? self::body($request) : $request->getQueryParams();
        $params = Input::object($params, 'request', [], self::KEYS);
        $page = isset($params['page']) ? self::int($params['page'], 'page') : 1;
        $size = isset($params['size']) ? self::int($params['size'], 'size') : $endpoint->pageSize;
        if ($size > $endpoint->pageSize) {
            throw new InvalidArgumentException(sprintf('size is at most %d on this endpoint, %d given.', $endpoint->pageSize, $size));
        }
        Assert::positive($page, 'page');
        Assert::positive($size, 'size');
        $query = Query::on($schema->index->value)->paginate($page, $size);
        $text = isset($params['q']) ? trim(self::string($params['q'], 'q')) : '';
        if ($text !== '' && count(preg_split('/\s+/', $text) ?: []) > self::MAX_TERMS) {
            throw new InvalidArgumentException(sprintf('q is at most %d words.', self::MAX_TERMS));
        }
        if ($text !== '') {
            $query = $query->match($endpoint->queryFields, $text);
            foreach ($endpoint->highlight as $field) {
                $query = $query->highlight($field);
            }
        }
        if (isset($params['vector'])) {
            $query = self::vector($query, $params['vector'], $endpoint, $page * $size);
        }
        foreach (self::filters($params['filter'] ?? [], $endpoint, $schema) as $filter) {
            $query = $query->filter($filter);
        }
        foreach (self::sorts($params['sort'] ?? null, $endpoint) as $sort) {
            $query = $query->sort($sort->field, $sort->direction);
        }
        foreach ($endpoint->facets as $field) {
            $query = $query->facet($field);
        }

        return new self($query);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function body(ServerRequestInterface $request): array
    {
        $size = $request->getBody()->getSize();
        if ($size !== null && $size > self::MAX_BODY_BYTES) {
            throw new InvalidArgumentException(sprintf('The request body is at most %d bytes.', self::MAX_BODY_BYTES));
        }
        $raw = (string) $request->getBody();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            throw new InvalidArgumentException(sprintf('The request body is at most %d bytes.', self::MAX_BODY_BYTES));
        }
        if (trim($raw) === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new InvalidArgumentException('The request body is not JSON: ' . $invalid->getMessage(), 0, $invalid);
        }
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new InvalidArgumentException('The request body must be a JSON object.');
        }

        return $decoded;
    }

    private static function vector(Query $query, mixed $vector, Endpoint $endpoint, int $depth): Query
    {
        if ($endpoint->vectorField === null) {
            throw new InvalidArgumentException('vector: this endpoint is not hybrid.');
        }
        $components = [];
        foreach (Input::list($vector, 'vector') as $i => $component) {
            $components[] = (float) Input::number($component, 'vector[' . $i . ']');
        }
        $query = $query->vector($endpoint->vectorField, $components, min($depth, Searcher::FUSION_WINDOW, VectorClause::MAX_K));

        return $endpoint->fusion === null ? $query : $query->fuse($endpoint->fusion);
    }

    /**
     * @return list<Filter>
     */
    private static function filters(mixed $filters, Endpoint $endpoint, IndexSchema $schema): array
    {
        if ($filters === [] || $filters === null) {
            return [];
        }
        if (!is_array($filters) || array_is_list($filters)) {
            throw new InvalidArgumentException('filter must be an object of fields.');
        }
        $built = [];
        foreach ($filters as $name => $value) {
            $name = (string) $name;
            if (!in_array($name, $endpoint->filterable, true)) {
                throw new InvalidArgumentException(sprintf('filter.%s: this endpoint filters by %s.', self::key($name), $endpoint->filterable === [] ? 'nothing' : implode(', ', $endpoint->filterable)));
            }
            $field = $schema->fieldAt($name) ?? throw new InvalidArgumentException(sprintf('filter.%s is not declared.', $name));
            $built[] = self::filter($name, $value, $field);
        }

        return $built;
    }

    private static function filter(string $name, mixed $value, Field $field): Filter
    {
        $path = 'filter.' . $name;
        if (!is_array($value)) {
            return Filter::term($name, self::typed($value, $field, $path));
        }
        if ($value === []) {
            throw new InvalidArgumentException(sprintf('%s needs a value, a list of values or bounds.', $path));
        }
        if (array_is_list($value)) {
            $values = [];
            foreach ($value as $i => $one) {
                $values[] = self::typed($one, $field, $path . '[' . $i . ']');
            }

            return Filter::terms($name, $values);
        }
        $bounds = [];
        foreach ($value as $bound => $one) {
            if (!in_array($bound, self::BOUNDS, true)) {
                throw new InvalidArgumentException(sprintf('%s.%s is not a bound (gt, gte, lt, lte).', $path, self::key((string) $bound)));
            }
            $typed = self::typed($one, $field, $path . '.' . $bound);
            $bounds[$bound] = is_bool($typed) ? throw new InvalidArgumentException(sprintf('%s.%s: a boolean has no range.', $path, $bound)) : $typed;
        }

        return Filter::range($name, ...$bounds);
    }

    /**
     * A query-string value is text; it is read as the field's type. A JSON value is kept as given, so a number stays a
     * number and the searcher judges it against the schema.
     */
    private static function typed(mixed $value, Field $field, string $path): string|int|float|bool
    {
        if (is_array($value) || $value === null) {
            throw new InvalidArgumentException(sprintf('%s must be a value.', $path));
        }
        if (!is_string($value)) {
            return Input::scalar($value, $path);
        }
        Assert::value($value, $path);

        return match ($field->type) {
            FieldType::Integer => preg_match('/^-?[0-9]{1,18}\z/', $value) === 1 ? (int) $value : throw new InvalidArgumentException(sprintf('%s must be a whole number, %s given.', $path, Assert::show($value))),
            FieldType::Float => is_numeric($value) ? (float) $value : throw new InvalidArgumentException(sprintf('%s must be a number, %s given.', $path, Assert::show($value))),
            FieldType::Boolean => match ($value) {
                'true', '1' => true,
                'false', '0' => false,
                default => throw new InvalidArgumentException(sprintf('%s must be true or false, %s given.', $path, Assert::show($value))),
            },
            default => $value,
        };
    }

    /**
     * @return list<Sort>
     */
    private static function sorts(mixed $sort, Endpoint $endpoint): array
    {
        if ($sort === null || $sort === '' || $sort === []) {
            return $endpoint->defaultSort === null ? [] : [$endpoint->defaultSort];
        }
        $specs = is_string($sort) ? explode(',', $sort) : Input::list($sort, 'sort');
        $sorts = [];
        $seen = [];
        foreach ($specs as $i => $spec) {
            $spec = trim(self::string($spec, 'sort[' . $i . ']'));
            $parsed = Endpoint::sort($spec);
            if (!in_array($parsed->field, $endpoint->sortable, true) && $parsed->field !== Sort::SCORE) {
                throw new InvalidArgumentException(sprintf('sort %s: this endpoint sorts by %s.', Assert::show($spec), $endpoint->sortable === [] ? '_score only' : implode(', ', $endpoint->sortable)));
            }
            if (isset($seen[$parsed->field])) {
                throw new InvalidArgumentException(sprintf('sort names %s twice.', Assert::show($parsed->field)));
            }
            $seen[$parsed->field] = true;
            $sorts[] = $parsed;
        }

        return $sorts;
    }

    private static function int(mixed $value, string $path): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]{1,9}\z/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException(sprintf('%s must be a positive whole number.', $path));
    }

    private static function string(mixed $value, string $path): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a string.', $path));
        }

        return $value;
    }

    private static function key(string $key): string
    {
        return preg_match('/^[A-Za-z0-9_.]{1,64}\z/', $key) === 1 ? $key : Assert::show($key);
    }
}
