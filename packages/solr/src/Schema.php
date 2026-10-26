<?php

declare(strict_types=1);

namespace Orion\Solr;

use InvalidArgumentException;
use Orion\Adapter\Capability;
use Orion\Adapter\UnsupportedClause;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\VectorMetric;
use Orion\Support\Assert;

use function array_key_exists;
use function array_keys;
use function explode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_scalar;
use function preg_match;
use function sprintf;
use function str_replace;

/**
 * The neutral schema as Solr fields, and back (v1 §6). The field type's name carries what Solr's own definition cannot
 * (`orion_keyword_sort` is sortable, `orion_text_en_folded` English with folding), so the schema Solr reports reads
 * back as the declaration with no side metadata; a type Orion did not name, or whose definition no longer matches its
 * name, reads back as unknown, so a native change can never pass for the declared field. Objects flatten into dotted
 * fields. Every field is single-valued; only text is stored, for highlighting: the source comes back from
 * `orion_source`, exactly as written.
 */
final class Schema
{
    /** Fields the configset declares for Orion itself, never the application's. */
    public const array INTERNAL = ['id', '_version_', 'orion_version', 'orion_tombstone', 'orion_source'];

    private const array SCALARS = [
        'keyword' => 'solr.StrField',
        'integer' => 'solr.LongPointField',
        'float' => 'solr.DoublePointField',
        'date' => 'solr.DatePointField',
        'boolean' => 'solr.BoolField',
        'geo' => 'solr.LatLonPointSpatialField',
    ];
    private const array METRICS = ['cosine' => 'cosine', 'dot_product' => 'dot_product', 'euclidean' => 'euclidean'];

    /**
     * Each language's analysis after the standard tokenizer and lower-casing: the stemmer Lucene has for it, and where
     * the language needs one, its own tokenizer or lower-casing. The same languages the Elasticsearch adapter maps.
     */
    private const array LANGUAGES = [
        'ar' => ['filters' => ['solr.ArabicNormalizationFilterFactory', 'solr.ArabicStemFilterFactory']],
        'bg' => ['filters' => ['solr.BulgarianStemFilterFactory']],
        'ca' => ['snowball' => 'Catalan'],
        'cs' => ['filters' => ['solr.CzechStemFilterFactory']],
        'da' => ['snowball' => 'Danish'],
        'de' => ['filters' => ['solr.GermanNormalizationFilterFactory'], 'snowball' => 'German'],
        'el' => ['lowercase' => 'solr.GreekLowerCaseFilterFactory', 'filters' => ['solr.GreekStemFilterFactory']],
        'en' => ['filters' => ['solr.EnglishPossessiveFilterFactory', 'solr.PorterStemFilterFactory']],
        'es' => ['snowball' => 'Spanish'],
        'eu' => ['snowball' => 'Basque'],
        'fa' => ['filters' => ['solr.PersianNormalizationFilterFactory']],
        'fi' => ['snowball' => 'Finnish'],
        'fr' => ['snowball' => 'French'],
        'ga' => ['lowercase' => 'solr.IrishLowerCaseFilterFactory', 'snowball' => 'Irish'],
        'gl' => ['filters' => ['solr.GalicianStemFilterFactory']],
        'hi' => ['filters' => ['solr.IndicNormalizationFilterFactory', 'solr.HindiNormalizationFilterFactory', 'solr.HindiStemFilterFactory']],
        'hu' => ['snowball' => 'Hungarian'],
        'hy' => ['snowball' => 'Armenian'],
        'id' => ['filters' => ['solr.IndonesianStemFilterFactory']],
        'it' => ['snowball' => 'Italian'],
        'lt' => ['snowball' => 'Lithuanian'],
        'lv' => ['filters' => ['solr.LatvianStemFilterFactory']],
        'nl' => ['snowball' => 'Dutch'],
        'no' => ['snowball' => 'Norwegian'],
        'pt' => ['snowball' => 'Portuguese'],
        'pt-br' => ['filters' => ['solr.BrazilianStemFilterFactory']],
        'ro' => ['snowball' => 'Romanian'],
        'ru' => ['snowball' => 'Russian'],
        'sv' => ['snowball' => 'Swedish'],
        'th' => ['tokenizer' => 'solr.ThaiTokenizerFactory'],
        'tr' => ['lowercase' => 'solr.TurkishLowerCaseFilterFactory', 'snowball' => 'Turkish'],
    ];

    private const string TEXT = '/^orion_text(?:_([a-z]{2,3}(?:_(?!folded\z)[a-z0-9]{2,8})?))?(_folded)?\z/';
    private const string SCALAR = '/^orion_(keyword|integer|float|date|boolean|geo)(_sort)?(_facet)?\z/';
    private const string VECTOR = '/^orion_vector_([0-9]+)_(cosine|dot_product|euclidean)\z/';

    /**
     * The languages a text field may declare.
     *
     * @return list<string>
     */
    public static function languages(): array
    {
        return array_keys(self::LANGUAGES);
    }

    /**
     * The Schema API definitions for these fields: each field type they need, once, and each field.
     *
     * @param list<Field> $fields
     * @return array{types: list<array{name: string, definition: array<string, mixed>}>, fields: list<array{name: string, type: string, indexed: bool, stored: bool}>}
     */
    public static function definitions(array $fields, string $prefix = ''): array
    {
        $types = [];
        $definitions = [];
        foreach ($fields as $field) {
            $name = $prefix . $field->name;
            if (in_array($name, self::INTERNAL, true)) {
                throw new InvalidArgumentException(sprintf('The field %s is Orion\'s own on Solr; name it otherwise.', Assert::show($name)));
            }
            if ($field->type === FieldType::Object) {
                $children = self::definitions($field->fields, $name . '.');
                foreach ($children['types'] as $type) {
                    $types[$type['name']] = $type;
                }
                $definitions = [...$definitions, ...$children['fields']];
                continue;
            }
            $type = self::typeName($field);
            $types[$type] = ['name' => $type, 'definition' => self::type($field)];
            $definitions[] = ['name' => $name, 'type' => $type, 'indexed' => self::indexed($field), 'stored' => $field->type === FieldType::Text];
        }

        return ['types' => array_values($types), 'fields' => $definitions];
    }

    /**
     * The neutral fields of what Solr reports (`/schema/fields?showDefaults=true` and `/schema/fieldtypes`).
     *
     * @param list<array<array-key, mixed>> $fields
     * @param list<array<array-key, mixed>> $types
     * @return list<Field>
     */
    public static function fields(array $fields, array $types): array
    {
        $byName = [];
        foreach ($types as $type) {
            if (is_scalar($type['name'] ?? null)) {
                $byName[(string) $type['name']] = $type;
            }
        }
        $tree = [];
        foreach ($fields as $field) {
            $name = is_scalar($field['name'] ?? null) ? (string) $field['name'] : '';
            if ($name === '' || in_array($name, self::INTERNAL, true)) {
                continue;
            }
            $type = is_scalar($field['type'] ?? null) ? (string) $field['type'] : '';
            $path = explode('.', $name);
            $tree = self::place($tree, $path, self::field($path[array_key_last($path)], $field, $type, $byName[$type] ?? []));
        }

        return self::assemble($tree);
    }

    private static function typeName(Field $field): string
    {
        return match ($field->type) {
            FieldType::Text => 'orion_text' . ($field->language === null ? '' : '_' . str_replace('-', '_', self::language($field->language))) . ($field->folding ? '_folded' : ''),
            FieldType::Vector => sprintf('orion_vector_%d_%s', (int) $field->dims, ($field->metric ?? VectorMetric::Cosine)->value),
            FieldType::Nested => throw UnsupportedClause::missing(Capability::NestedDocuments, 'nested field ' . Assert::show($field->name), SolrEngine::NAME),
            FieldType::Object, FieldType::Unknown => throw new InvalidArgumentException(sprintf('The field %s has no Solr type.', Assert::show($field->name))),
            default => 'orion_' . $field->type->value . ($field->sortable ? '_sort' : '') . ($field->facetable ? '_facet' : ''),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function type(Field $field): array
    {
        return match ($field->type) {
            FieldType::Text => ['class' => 'solr.TextField', 'positionIncrementGap' => '100', 'analyzer' => self::analyzer($field)],
            FieldType::Vector => ['class' => 'solr.DenseVectorField', 'vectorDimension' => (int) $field->dims, 'similarityFunction' => self::METRICS[($field->metric ?? VectorMetric::Cosine)->value]],
            // Documents missing a sort field come last in either direction, as the neutral sort means.
            FieldType::Geo => ['class' => self::SCALARS['geo'], 'docValues' => $field->sortable],
            default => [
                'class' => self::SCALARS[$field->type->value] ?? throw new InvalidArgumentException(sprintf('The field %s has no Solr type.', Assert::show($field->name))),
                'docValues' => $field->sortable || $field->facetable,
                'sortMissingLast' => true,
            ],
        };
    }

    private static function indexed(Field $field): bool
    {
        return in_array($field->type, [FieldType::Text, FieldType::Vector], true) || $field->filterable;
    }

    /**
     * @return array{tokenizer: array{class: string}, filters: list<array<string, string>>}
     */
    private static function analyzer(Field $field): array
    {
        $language = $field->language === null ? [] : self::LANGUAGES[self::language($field->language)];
        $filters = [['class' => $language['lowercase'] ?? 'solr.LowerCaseFilterFactory']];
        if ($field->folding) {
            $filters[] = ['class' => 'solr.ASCIIFoldingFilterFactory'];
        }
        foreach ($language['filters'] ?? [] as $filter) {
            $filters[] = ['class' => $filter];
        }
        if (isset($language['snowball'])) {
            $filters[] = ['class' => 'solr.SnowballPorterFilterFactory', 'language' => $language['snowball']];
        }

        return ['tokenizer' => ['class' => $language['tokenizer'] ?? 'solr.StandardTokenizerFactory'], 'filters' => $filters];
    }

    private static function language(string $iso): string
    {
        return array_key_exists($iso, self::LANGUAGES) ? $iso : throw new InvalidArgumentException(sprintf('Orion has no Solr analysis for the language %s.', Assert::show($iso)));
    }

    /**
     * @param array<array-key, mixed> $field
     * @param array<array-key, mixed> $type
     */
    private static function field(string $name, array $field, string $typeName, array $type): Field
    {
        $class = is_scalar($type['class'] ?? null) ? (string) $type['class'] : '';
        $indexed = ($field['indexed'] ?? true) === true;
        if (preg_match(self::TEXT, $typeName, $text) === 1 && $class === 'solr.TextField') {
            $language = ($text[1] ?? '') === '' ? null : str_replace('_', '-', $text[1]);
            if ($language === null || array_key_exists($language, self::LANGUAGES)) {
                return Field::text($name, $language, folding: ($text[2] ?? '') !== '');
            }
        }
        if (
            preg_match(self::VECTOR, $typeName, $vector) === 1 && $class === 'solr.DenseVectorField'
            && is_numeric($type['vectorDimension'] ?? null) && (string) $type['vectorDimension'] === $vector[1]
            && ($type['similarityFunction'] ?? null) === $vector[2]
        ) {
            return Field::vector($name, (int) $vector[1], VectorMetric::from($vector[2]));
        }
        if (preg_match(self::SCALAR, $typeName, $scalar) === 1 && $class === self::SCALARS[$scalar[1]]) {
            $sortable = ($scalar[2] ?? '') !== '';
            $facetable = ($scalar[3] ?? '') !== '';
            if (self::flag($type['docValues'] ?? false) === ($sortable || $facetable)) {
                return match ($scalar[1]) {
                    'keyword' => Field::keyword($name, $indexed, $sortable, $facetable),
                    'integer' => Field::integer($name, $indexed, $sortable, $facetable),
                    'float' => Field::float($name, $indexed, $sortable, $facetable),
                    'date' => Field::date($name, $indexed, $sortable, $facetable),
                    'boolean' => $sortable ? Field::unknown($name) : Field::boolean($name, $indexed, $facetable),
                    'geo' => $facetable ? Field::unknown($name) : Field::geo($name, $indexed, $sortable),
                };
            }
        }

        return Field::unknown($name);
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 'true';
    }

    /**
     * @param array<string, mixed> $tree
     * @param list<string> $path
     * @return array<string, mixed>
     */
    private static function place(array $tree, array $path, Field $field): array
    {
        $head = $path[0];
        if (count($path) === 1) {
            $tree[$head] = $field;

            return $tree;
        }
        $branch = is_array($tree[$head] ?? null) ? $tree[$head] : [];
        $tree[$head] = self::place($branch, array_slice($path, 1), $field);

        return $tree;
    }

    /**
     * @param array<string, mixed> $tree
     * @return list<Field>
     */
    private static function assemble(array $tree): array
    {
        $fields = [];
        foreach ($tree as $name => $node) {
            $fields[] = $node instanceof Field ? $node : Field::object((string) $name, ...self::assemble(is_array($node) ? $node : []));
        }

        return $fields;
    }
}
