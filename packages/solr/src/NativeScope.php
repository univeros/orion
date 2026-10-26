<?php

declare(strict_types=1);

namespace Orion\Solr;

use InvalidArgumentException;
use LogicException;
use Orion\Query\Page;
use Orion\Support\Assert;

use function array_is_list;
use function chr;
use function hexdec;
use function implode;
use function in_array;
use function is_array;
use function is_bool;
use function is_scalar;
use function preg_match;
use function preg_replace_callback;
use function sprintf;
use function str_replace;
use function str_starts_with;
use function strtolower;

/**
 * The escape hatch's request: the native parameters, restricted to live documents and, under a scope, to the scope.
 * Solr cannot be told which query parsers to refuse, and local parameters nest, quote, escape and dereference past
 * any pattern, so a scoped request is held to what can be checked: Lucene, dismax or edismax syntax with no local
 * parameters, nested queries or magic fields anywhere (unescaped first), no function reading collection-wide
 * statistics, and only parameters that act within the matched documents (querying, sorting, paging, highlighting
 * the matched fields), named exactly. Facets, stats, terms, joins, other collections and the like are thereby
 * refused: each would let one tenant's query read or use another tenant's documents. The envelope always reads its
 * own fields. Unscoped, single tenancy keeps the full escape hatch.
 */
final class NativeScope
{
    /** Parameters a scoped request may set, by their exact names, besides lower-case parameters of its own. */
    private const array ALLOWED = [
        'q', 'fq', 'defType', 'df', 'qf', 'pf', 'pf2', 'pf3', 'ps', 'ps2', 'ps3', 'qs', 'mm', 'q.op', 'tie', 'bq', 'bf',
        'boost', 'uf', 'sort', 'start', 'rows', 'cursorMark', 'timeAllowed', 'fl', 'wt', 'hl', 'hl.fl', 'hl.snippets',
        'hl.fragsize', 'hl.method', 'hl.encoder', 'hl.tag.pre', 'hl.tag.post', 'hl.requireFieldMatch',
        'hl.usePhraseHighlighter', 'hl.highlightMultiTerm', 'hl.maxAnalyzedChars', 'hl.defaultSummary',
    ];
    /** Solr's own lower-case parameters that read outside the matched documents, or reach elsewhere. */
    private const array DENIED = [
        'facet', 'stats', 'group', 'terms', 'spellcheck', 'mlt', 'expand', 'debug', 'qt', 'shards', 'collection',
        'json', 'distrib', 'tv', 'suggest', 'clustering', 'stream', 'rq', 'cache',
    ];
    /** The parsers a scoped request may name as its default. */
    private const array PARSERS = ['lucene', 'dismax', 'edismax'];
    /** Constructs that run another parser or read across the collection, wherever they appear in a value. */
    private const string NESTED = '/\{!|_query_|_val_/';
    private const string STATISTICS = '/\b(docfreq|idf|maxdoc|numdocs|totaltermfreq|ttf|sumtotaltermfreq|sttf)\s*\(/';
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

        return [...$params, ...$bound, 'fq' => [...$filters, QueryCompiler::LIVE, ...$scope], 'fl' => QueryCompiler::FIELDS, 'wt' => 'json'];
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
            if (str_starts_with(strtolower($name), self::SCOPE_PREFIX)) {
                throw new InvalidArgumentException(sprintf('A scoped native query cannot set %s: the scope binds it.', Assert::show($name)));
            }
            $own = preg_match('/^[a-z][a-z0-9_]*\z/', $name) === 1 && !in_array($name, self::DENIED, true);
            if (!in_array($name, self::ALLOWED, true) && !$own) {
                throw new InvalidArgumentException(sprintf('A scoped native query cannot use %s: it reads outside the scope. Allowed: %s, and lower-case parameters of your own.', Assert::show($name), implode(', ', self::ALLOWED)));
            }
            foreach (is_array($value) ? $value : [$value] as $one) {
                self::refuseValue($name, $one);
            }
        }
        foreach (['defType' => self::PARSERS] as $name => $allowed) {
            $values = (array) ($params[$name] ?? []);
            foreach ($values as $parser) {
                if (!in_array($parser, $allowed, true)) {
                    throw new InvalidArgumentException(sprintf('A scoped native query parses with %s only, not %s.', implode(', ', $allowed), Assert::show($parser)));
                }
            }
        }
        foreach ((array) ($params['rows'] ?? []) as $rows) {
            if (preg_match('/^[0-9]+\z/', $rows) !== 1 || (int) $rows > Page::MAX_SIZE) {
                throw new InvalidArgumentException(sprintf('A scoped native query reads at most %d rows, %s asked.', Page::MAX_SIZE, Assert::show($rows)));
            }
        }
    }

    /**
     * Read as Solr reads it: Lucene unescapes `\uXXXX` and `\x` before a nested parser or magic field sees the text;
     * only ASCII matters to what is refused.
     */
    private static function refuseValue(string $name, string $value): void
    {
        $plain = strtolower(str_replace('\\', '', (string) preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', static fn(array $hex): string => hexdec($hex[1]) < 0x80 ? chr((int) hexdec($hex[1])) : '?', $value)));
        if (preg_match(self::NESTED, $plain) === 1) {
            throw new InvalidArgumentException(sprintf('A scoped native query cannot use local parameters, nested queries or magic fields (in %s): they can run parsers that read other documents.', Assert::show($name)));
        }
        if (preg_match(self::STATISTICS, $plain) === 1) {
            throw new InvalidArgumentException(sprintf('A scoped native query cannot read collection-wide statistics (in %s): they count other tenants\' documents.', Assert::show($name)));
        }
    }
}
