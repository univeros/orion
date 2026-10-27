<?php

declare(strict_types=1);

namespace Orion\Solr;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use LogicException;
use Orion\Adapter\Capability;
use Orion\Adapter\UnsupportedClause;
use Orion\Query\BoolFilter;
use Orion\Query\Clause;
use Orion\Query\ClauseKind;
use Orion\Query\ExistsFilter;
use Orion\Query\Facet;
use Orion\Query\Filter;
use Orion\Query\Query;
use Orion\Query\RangeFilter;
use Orion\Query\Sort;
use Orion\Query\TermFilter;
use Orion\Query\TermsFilter;
use Orion\Schema\FieldType;
use Orion\Support\Assert;

use function addcslashes;
use function array_filter;
use function array_keys;
use function array_values;
use function floor;
use function array_map;
use function implode;
use function is_bool;
use function is_float;
use function is_string;
use function json_encode;
use function preg_match;
use function preg_replace;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strtr;

use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;

/**
 * The neutral query as Solr request parameters, a pure function (the basis of WP9's `compile_query`). Every value the
 * caller gave is bound as its own parameter and referenced (`{!term f=brand v=$o1}`), never spliced into query syntax,
 * so no value can change the query's shape; field names are already restricted to names. Clauses score and are
 * conjunctive (`{!bool must=...}`); filters are filter queries, ANDed, and the k-NN leg's pre-filters; tombstones are
 * excluded from every search.
 */
final class QueryCompiler
{
    /** Live documents only: a delete leaves a tombstone in the index (see the configset). */
    public const string LIVE = '-orion_tombstone:true';
    public const string FIELDS = 'id,orion_source,score';
    public const string ALL = '*:*';
    private const string ISO = '/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?)?(?:Z|[+-]\d{2}:?\d{2})?\z/';

    /** @var array<string, string> */
    private array $bound = [];

    public function __construct(private readonly string $engine = SolrEngine::NAME)
    {
    }

    /**
     * Whether compiling needs the index's field types, which only the schema tells: a date given as a string is an
     * instant on a date field and text elsewhere, a whole float is an integer on an integer field, and an object exists
     * when any of its fields does.
     */
    public function needsTypes(Query $query): bool
    {
        foreach ($query->filters as $filter) {
            if (self::readsTypes($filter)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, FieldType> $types the index's field types by (dotted) name, for range bounds
     * @return array<string, string|list<string>>
     */
    public function compile(Query $query, array $types = []): array
    {
        if ($query->vector !== null && $query->clauses !== []) {
            throw UnsupportedClause::missing(Capability::HybridFusion, 'hybrid retrieval', $this->engine);
        }
        $this->bound = [];
        $q = $query->vector !== null
            ? sprintf('{!knn f=%s topK=%d v=%s}', $query->vector->field, $query->vector->k, $this->bind(json_encode($query->vector->vector, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)))
            : $this->clauses($query->clauses);
        $filters = [self::LIVE];
        foreach ($query->filters as $filter) {
            $filters[] = $this->filter($filter, $types);
        }
        $params = [
            'q' => $q,
            'fq' => $filters,
            'fl' => self::FIELDS,
            'start' => (string) (($query->page->page - 1) * $query->page->size),
            'rows' => (string) $query->page->size,
        ];
        if ($query->sorts !== []) {
            // Relevance is Solr's `score` pseudo-field.
            $params['sort'] = implode(',', array_map(static fn(Sort $sort): string => ($sort->field === Sort::SCORE ? 'score' : $sort->field) . ' ' . $sort->direction->value, $query->sorts));
        }
        if ($query->facets !== []) {
            $params['facet'] = 'true';
            $params['facet.mincount'] = '1';
            $params['facet.field'] = array_map(static fn(Facet $facet): string => $facet->field, $query->facets);
            foreach ($query->facets as $facet) {
                $params['f.' . $facet->field . '.facet.limit'] = (string) $facet->size;
            }
        }
        if ($query->highlights !== []) {
            // The html encoder escapes the stored text, so a fragment's only markup is the <em> around matches.
            $params = [...$params, 'hl' => 'true', 'hl.fl' => implode(',', $query->highlights), 'hl.method' => 'unified', 'hl.requireFieldMatch' => 'true', 'hl.encoder' => 'html', 'hl.tag.pre' => '<em>', 'hl.tag.post' => '</em>'];
        }

        return [...$params, ...$this->bound];
    }

    /**
     * The filter queries restricting a native request to a scope.
     *
     * @param array<Filter> $scope
     * @return array{list<string>, array<string, string>} the filter queries and the parameters they reference
     */
    public function scopeFilters(array $scope, string $prefix): array
    {
        $this->bound = [];
        $filters = [];
        foreach ($scope as $filter) {
            $filters[] = $this->filter($filter, [], $prefix);
        }
        $bound = $this->bound;
        $this->bound = [];

        return [$filters, $bound];
    }

    /**
     * @param list<Clause> $clauses
     */
    private function clauses(array $clauses): string
    {
        if ($clauses === []) {
            return self::ALL;
        }
        if (count($clauses) === 1) {
            return $this->clause($clauses[0]);
        }

        return '{!bool ' . implode(' ', array_map(fn(Clause $clause): string => 'must=' . $this->bind($this->clause($clause)), $clauses)) . '}';
    }

    private function clause(Clause $clause): string
    {
        $value = self::scalar($clause->value);
        if ($clause->kind === ClauseKind::Match) {
            // Plain text, as the neutral match means it: dismax reads quotes, and + or - starting a word, as syntax.
            // The fields and their weights are dismax's qf, bound as a parameter like every value.
            $fields = [];
            foreach ($clause->fields as $field => $weight) {
                $fields[] = $weight === Clause::DEFAULT_WEIGHT ? $field : $field . '^' . self::weight($weight);
            }

            return sprintf('{!dismax qf=%s mm=1 v=%s}', $this->bind(implode(' ', $fields)), $this->bind((string) preg_replace('/(^|\s)[+-]+/', '$1', strtr($value, ['"' => ' ']))));
        }
        $compiled = match ($clause->kind) {
            ClauseKind::Term => sprintf('{!term f=%s v=%s}', $clause->field, $this->bind($value)),
            ClauseKind::Prefix => sprintf('{!prefix f=%s v=%s}', $clause->field, $this->bind($value)),
            ClauseKind::Phrase => sprintf('{!field f=%s v=%s}', $clause->field, $this->bind($value)),
        };
        $weight = $clause->fields[$clause->field];

        return $weight === Clause::DEFAULT_WEIGHT ? $compiled : sprintf('{!boost b=%s v=%s}', self::weight($weight), $this->bind($compiled));
    }

    /**
     * A weight as Solr reads it: a plain decimal, never exponent notation.
     */
    private static function weight(float $weight): string
    {
        return rtrim(rtrim(sprintf('%.6F', $weight), '0'), '.');
    }

    /**
     * @param array<string, FieldType> $types
     */
    private function filter(Filter $filter, array $types, string $prefix = 'o'): string
    {
        return match (true) {
            $filter instanceof TermFilter => $this->term($filter->field, $filter->value, $prefix, $types[$filter->field] ?? null),
            $filter instanceof TermsFilter => '{!bool ' . implode(' ', array_map(
                fn(string|int|float|bool $value): string => 'should=' . $this->bind($this->term($filter->field, $value, $prefix, $types[$filter->field] ?? null), $prefix),
                $filter->values,
            )) . '}',
            $filter instanceof RangeFilter => sprintf('{!lucene v=%s}', $this->bind($this->range($filter, $types[$filter->field] ?? null), $prefix)),
            $filter instanceof ExistsFilter => $this->exists($filter->field, $types, $prefix),
            $filter instanceof BoolFilter => $this->bool($filter, $types, $prefix),
            default => throw new LogicException('Unknown filter ' . $filter::class),
        };
    }

    private function term(string $field, string|int|float|bool $value, string $prefix, ?FieldType $type = null): string
    {
        $read = match (true) {
            $type === FieldType::Date && is_string($value) => self::instant($value, $field),
            $type === FieldType::Integer && is_float($value) && floor($value) === $value => (string) (int) $value,
            default => self::scalar($value),
        };

        return sprintf('{!term f=%s v=%s}', $field, $this->bind($read, $prefix));
    }

    /**
     * An object exists when any of its (dotted) fields does.
     *
     * @param array<string, FieldType> $types
     */
    private function exists(string $field, array $types, string $prefix): string
    {
        $children = array_values(array_filter(array_keys($types), static fn(string $name): bool => str_starts_with($name, $field . '.')));
        if ($children === [] || isset($types[$field])) {
            return $field . ':[* TO *]';
        }

        return '{!bool ' . implode(' ', array_map(fn(string $child): string => 'should=' . $this->bind($child . ':[* TO *]', $prefix), $children)) . '}';
    }

    /**
     * A bool filter as the neutral one means it: `should` requires one match even beside `must`, and `must_not` alone
     * excludes from everything rather than matching nothing.
     *
     * @param array<string, FieldType> $types
     */
    private function bool(BoolFilter $filter, array $types, string $prefix): string
    {
        $must = array_map(fn(Filter $child): string => $this->bind($this->filter($child, $types, $prefix), $prefix), $filter->must);
        $should = array_map(fn(Filter $child): string => $this->bind($this->filter($child, $types, $prefix), $prefix), $filter->should);
        $mustNot = array_map(fn(Filter $child): string => $this->bind($this->filter($child, $types, $prefix), $prefix), $filter->mustNot);
        if ($must !== [] && $should !== []) {
            $must[] = $this->bind('{!bool ' . implode(' ', array_map(static fn(string $ref): string => 'should=' . $ref, $should)) . '}', $prefix);
            $should = [];
        }
        if ($must === [] && $should === []) {
            if ($mustNot === []) {
                return self::ALL;
            }
            $must[] = $this->bind(self::ALL, $prefix);
        }
        $parts = [
            ...array_map(static fn(string $ref): string => 'must=' . $ref, $must),
            ...array_map(static fn(string $ref): string => 'should=' . $ref, $should),
            ...array_map(static fn(string $ref): string => 'must_not=' . $ref, $mustNot),
        ];

        return '{!bool ' . implode(' ', $parts) . '}';
    }

    private function range(RangeFilter $filter, ?FieldType $type): string
    {
        $lower = $filter->gt ?? $filter->gte;
        $upper = $filter->lt ?? $filter->lte;

        return sprintf(
            '%s:%s%s TO %s%s',
            $filter->field,
            $filter->gt !== null ? '{' : '[',
            $lower === null ? '*' : self::bound($lower, $type, $filter->field),
            $upper === null ? '*' : self::bound($upper, $type, $filter->field),
            $filter->lt !== null ? '}' : ']',
        );
    }

    private static function bound(int|float|string $value, ?FieldType $type, string $field): string
    {
        if (!is_string($value)) {
            return is_float($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) : (string) $value;
        }
        if ($type === FieldType::Date) {
            $value = self::instant($value, $field);
        }

        return '"' . addcslashes($value, '"\\') . '"';
    }

    private static function instant(string $value, string $field): string
    {
        if (preg_match(self::ISO, $value) === 1) {
            try {
                $instant = (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));

                return $instant->format($instant->format('u') === '000000' ? 'Y-m-d\TH:i:s\Z' : 'Y-m-d\TH:i:s.v\Z');
            } catch (Exception) {
                // Refused below.
            }
        }

        throw new InvalidArgumentException(sprintf('The range bound %s on the date field %s is not an ISO 8601 date.', Assert::show($value), Assert::show($field)));
    }

    private static function readsTypes(Filter $filter): bool
    {
        $values = match (true) {
            $filter instanceof RangeFilter => [$filter->gt, $filter->gte, $filter->lt, $filter->lte],
            $filter instanceof TermFilter => [$filter->value],
            $filter instanceof TermsFilter => $filter->values,
            default => [],
        };
        foreach ($values as $value) {
            if (is_string($value) && ($filter instanceof RangeFilter || preg_match(self::ISO, $value) === 1) || is_float($value)) {
                return true;
            }
        }
        if ($filter instanceof ExistsFilter) {
            return true;
        }
        if ($filter instanceof BoolFilter) {
            foreach ([...$filter->must, ...$filter->should, ...$filter->mustNot] as $child) {
                if (self::readsTypes($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function scalar(string|int|float|bool $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            default => (string) $value,
        };
    }

    /**
     * Binds a value as the next parameter and returns its reference.
     */
    private function bind(string $value, string $prefix = 'o'): string
    {
        $name = $prefix . (count($this->bound) + 1);
        $this->bound[$name] = $value;

        return '$' . $name;
    }
}
