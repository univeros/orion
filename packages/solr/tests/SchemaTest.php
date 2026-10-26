<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

use InvalidArgumentException;
use Orion\Adapter\UnsupportedClause;
use Orion\Schema\Field;
use Orion\Schema\FieldType;
use Orion\Schema\VectorMetric;
use Orion\Solr\Schema;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;

final class SchemaTest extends TestCase
{
    public function testTheTypeNameCarriesTheNeutralAttributes(): void
    {
        $definitions = Schema::definitions([
            Field::keyword('sku', filterable: true, sortable: true),
            Field::keyword('brand', filterable: true, facetable: true),
            Field::keyword('note'),
            Field::integer('stock', sortable: true, facetable: true),
            Field::float('price', filterable: true),
            Field::date('released', sortable: true),
            Field::boolean('in_stock', filterable: true, facetable: true),
            Field::geo('at', filterable: true, sortable: true),
        ]);

        self::assertSame([
            ['name' => 'sku', 'type' => 'orion_keyword_sort', 'indexed' => true, 'stored' => false],
            ['name' => 'brand', 'type' => 'orion_keyword_facet', 'indexed' => true, 'stored' => false],
            ['name' => 'note', 'type' => 'orion_keyword', 'indexed' => false, 'stored' => false],
            ['name' => 'stock', 'type' => 'orion_integer_sort_facet', 'indexed' => false, 'stored' => false],
            ['name' => 'price', 'type' => 'orion_float', 'indexed' => true, 'stored' => false],
            ['name' => 'released', 'type' => 'orion_date_sort', 'indexed' => false, 'stored' => false],
            ['name' => 'in_stock', 'type' => 'orion_boolean_facet', 'indexed' => true, 'stored' => false],
            ['name' => 'at', 'type' => 'orion_geo_sort', 'indexed' => true, 'stored' => false],
        ], $definitions['fields']);
        self::assertSame(['class' => 'solr.StrField', 'docValues' => true], self::type($definitions, 'orion_keyword_sort'));
        self::assertSame(['class' => 'solr.StrField', 'docValues' => false], self::type($definitions, 'orion_keyword'));
        self::assertSame(['class' => 'solr.LongPointField', 'docValues' => true], self::type($definitions, 'orion_integer_sort_facet'));
        self::assertSame(['class' => 'solr.LatLonPointSpatialField', 'docValues' => true], self::type($definitions, 'orion_geo_sort'));
    }

    public function testTextIsStoredForHighlightingAndAnalysedPerLanguage(): void
    {
        $definitions = Schema::definitions([Field::text('title', 'en', folding: true), Field::text('plain'), Field::text('th', 'th')]);

        self::assertSame(['name' => 'title', 'type' => 'orion_text_en_folded', 'indexed' => true, 'stored' => true], $definitions['fields'][0]);
        $analyzer = self::type($definitions, 'orion_text_en_folded')['analyzer'];
        self::assertSame(['solr.StandardTokenizerFactory', ['solr.LowerCaseFilterFactory', 'solr.ASCIIFoldingFilterFactory', 'solr.EnglishPossessiveFilterFactory', 'solr.PorterStemFilterFactory']], [$analyzer['tokenizer']['class'], array_column($analyzer['filters'], 'class')]);
        self::assertSame(['solr.LowerCaseFilterFactory'], array_column(self::type($definitions, 'orion_text')['analyzer']['filters'], 'class'));
        self::assertSame('solr.ThaiTokenizerFactory', self::type($definitions, 'orion_text_th')['analyzer']['tokenizer']['class']);
    }

    public function testAnUnsupportedLanguageIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Schema::definitions([Field::text('title', 'xx')]);
    }

    public function testVectorsTakeATypePerDimensionAndMetric(): void
    {
        $definitions = Schema::definitions([Field::vector('embedding', 3, VectorMetric::DotProduct)]);

        self::assertSame(['name' => 'embedding', 'type' => 'orion_vector_3_dot_product', 'indexed' => true, 'stored' => false], $definitions['fields'][0]);
        self::assertSame(['class' => 'solr.DenseVectorField', 'vectorDimension' => 3, 'similarityFunction' => 'dot_product'], self::type($definitions, 'orion_vector_3_dot_product'));
    }

    public function testObjectsFlattenToDottedFields(): void
    {
        $definitions = Schema::definitions([Field::object('address', Field::keyword('city', filterable: true), Field::object('geo', Field::integer('floor')))]);

        self::assertSame(['address.city', 'address.geo.floor'], array_column($definitions['fields'], 'name'));
    }

    public function testNestedDocumentsAreNotSupported(): void
    {
        $this->expectException(UnsupportedClause::class);

        Schema::definitions([Field::nested('variants', Field::keyword('size'))]);
    }

    public function testWhatSolrReportsReadsBackAsTheDeclaration(): void
    {
        $declared = [
            Field::text('title', 'pt-br', folding: true),
            Field::text('summary', 'en', folding: true),
            Field::text('plain', folding: true),
            Field::keyword('sku', filterable: true, sortable: true, facetable: true),
            Field::boolean('in_stock', filterable: true),
            Field::date('released', filterable: true, sortable: true),
            Field::vector('embedding', 3, VectorMetric::Euclidean),
            Field::object('address', Field::keyword('city', filterable: true), Field::object('geo', Field::integer('floor', sortable: true))),
        ];
        $definitions = Schema::definitions($declared);
        $types = array_map(static fn(array $type): array => ['name' => $type['name'], ...$type['definition']], $definitions['types']);
        $reported = [...$definitions['fields'], ['name' => 'id', 'type' => 'orion_id', 'indexed' => true, 'stored' => true], ['name' => 'orion_source', 'type' => 'orion_stored', 'indexed' => false, 'stored' => true]];

        self::assertEquals(Field::mapToArray($declared), Field::mapToArray(Schema::fields($reported, $types)));
    }

    public function testATypeOrionDidNotNameOrThatWasRedefinedIsUnknown(): void
    {
        $fields = Schema::fields(
            [['name' => 'legacy', 'type' => 'text_general', 'indexed' => true], ['name' => 'sku', 'type' => 'orion_keyword', 'indexed' => true]],
            [['name' => 'text_general', 'class' => 'solr.TextField'], ['name' => 'orion_keyword', 'class' => 'solr.TextField']],
        );

        self::assertSame([FieldType::Unknown, FieldType::Unknown], array_map(static fn(Field $field): FieldType => $field->type, $fields));
    }

    /**
     * @param array{types: list<array{name: string, definition: array<string, mixed>}>, fields: list<array<string, mixed>>} $definitions
     * @return array<string, mixed>
     */
    private static function type(array $definitions, string $name): array
    {
        foreach ($definitions['types'] as $type) {
            if ($type['name'] === $name) {
                return $type['definition'];
            }
        }
        self::fail('No field type ' . $name);
    }
}
