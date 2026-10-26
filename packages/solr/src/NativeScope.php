<?php

declare(strict_types=1);

namespace Orion\Solr;

use InvalidArgumentException;
use LogicException;
use Orion\Support\Assert;

use function array_is_list;
use function array_key_exists;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_scalar;
use function preg_match;
use function preg_match_all;
use function sprintf;
use function str_starts_with;
use function strtolower;

/**
 * The escape hatch's request: the native parameters, restricted to live documents and, under a scope, to the scope.
 * Scoped, only parameters that act within the matched documents are accepted (querying, sorting, paging,
 * highlighting); anything that reads across the collection (facets, stats, terms, JSON facets, debugging), reaches
 * another collection, or matches through other documents (join, graph, more-like-this, block join, the XML parser),
 * which would let one tenant's query use another tenant's documents, is refused. The envelope always reads its own
 * fields. Unscoped, single tenancy keeps the full escape hatch.
 */
final class NativeScope
{
    /** Parameters a scoped request may set, besides `hl.*` and its own lower-case parameters. */
    private const array ALLOWED = [
        'q', 'fq', 'deftype', 'df', 'qf', 'pf', 'pf2', 'pf3', 'ps', 'ps2', 'ps3', 'qs', 'mm', 'q.op', 'tie', 'bq', 'bf',
        'boost', 'uf', 'sort', 'start', 'rows', 'cursormark', 'timeallowed', 'rq', 'hl', 'fl', 'wt',
    ];
    /** Solr's own parameters that read outside the matched documents, or reach elsewhere. */
    private const array DENIED = [
        'facet', 'stats', 'group', 'terms', 'spellcheck', 'mlt', 'expand', 'debug', 'debugquery', 'qt', 'shards',
        'collection', 'explainother', 'json', 'distrib', 'elevateids', 'excludeids', 'enableelevation',
        'forceelevation', 'tv', 'suggest', 'clustering', 'stream', 'echoparams', 'shards.qt',
    ];
    /** Query parsers that match through other documents. */
    private const array PARSERS = ['join', 'graph', 'graphterms', 'mlt', 'parent', 'child', 'xmlparser'];
    private const string SCOPE_PREFIX = 'orion_scope';

    /**
     * @param array<array-key, mixed> $native the engine's own parameters
     * @param list<string> $scope the scope's filter queries
     * @param array<string, string> $bound the parameters the scope's filter queries reference
     * @return array<string, string|list<string>>
     */
    public static function request(array $native, array $scope, array $bound): array
    {
        $params = self::parameters($native);
        if ($scope !== []) {
            self::refuseEscapes($params);
        }
        $filters = isset($params['fq']) ? (is_array($params['fq']) ? $params['fq'] : [$params['fq']]) : [];

        return [...$params, ...$bound, 'fq' => [...$filters, QueryCompiler::LIVE, ...$scope], 'fl' => QueryCompiler::FIELDS];
    }

    public static function prefix(): string
    {
        return self::SCOPE_PREFIX;
    }

    /**
     * @param array<array-key, mixed> $native
     * @return array<string, string|list<string>>
     */
    private static function parameters(array $native): array
    {
        $params = [];
        foreach ($native as $name => $value) {
            $values = is_array($value) && array_is_list($value) ? $value : [$value];
            $strings = [];
            foreach ($values as $one) {
                if (!is_scalar($one)) {
                    throw new LogicException(sprintf('A Solr native query is a callable returning request parameters: %s is not a value or a list of values.', Assert::show((string) $name)));
                }
                $strings[] = is_bool($one) ? ($one ? 'true' : 'false') : (string) $one;
            }
            $params[(string) $name] = is_array($value) ? $strings : $strings[0];
        }

        return $params;
    }

    /**
     * @param array<string, string|list<string>> $params
     */
    private static function refuseEscapes(array $params): void
    {
        foreach ($params as $name => $value) {
            $key = strtolower($name);
            if (str_starts_with($key, self::SCOPE_PREFIX)) {
                throw new InvalidArgumentException(sprintf('A scoped native query cannot set %s: the scope binds it.', Assert::show($name)));
            }
            $own = preg_match('/^[a-z][a-z0-9_]*\z/', $key) === 1 && !in_array($key, self::DENIED, true);
            if (!in_array($key, self::ALLOWED, true) && !str_starts_with($key, 'hl.') && !$own) {
                throw new InvalidArgumentException(sprintf('A scoped native query cannot use %s: it reads outside the scope. Allowed: %s, hl.*, and parameters of your own.', Assert::show($name), implode(', ', self::ALLOWED)));
            }
            foreach (is_array($value) ? $value : [$value] as $one) {
                self::refuseParsers($name, $one);
            }
        }
        if (array_key_exists('defType', $params) || array_key_exists('deftype', $params)) {
            $declared = $params['defType'] ?? $params['deftype'];
            $type = strtolower(is_array($declared) ? implode(',', $declared) : $declared);
            if (in_array($type, self::PARSERS, true)) {
                throw new InvalidArgumentException(sprintf('A scoped native query cannot parse with %s: it matches through other documents.', Assert::show($type)));
            }
        }
    }

    private static function refuseParsers(string $name, string $value): void
    {
        preg_match_all('/\{!([^}]*)\}?/', $value, $locals);
        foreach ($locals[1] as $local) {
            $type = preg_match('/^\s*([A-Za-z]+)\b(?!\s*=)/', $local, $first) === 1 ? strtolower($first[1]) : null;
            $named = preg_match('/\btype\s*=\s*[\'"]?([A-Za-z]+)/', $local, $declared) === 1 ? strtolower($declared[1]) : null;
            foreach ([$type, $named] as $parser) {
                if ($parser !== null && in_array($parser, self::PARSERS, true)) {
                    throw new InvalidArgumentException(sprintf('A scoped native query cannot use the %s parser (in %s): it matches through other documents.', $parser, Assert::show($name)));
                }
            }
            if (preg_match('/\bfromIndex\b/i', $local) === 1) {
                throw new InvalidArgumentException(sprintf('A scoped native query cannot read another index (fromIndex, in %s).', Assert::show($name)));
            }
        }
    }
}
