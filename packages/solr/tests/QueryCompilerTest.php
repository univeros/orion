<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

use InvalidArgumentException;
use Orion\Adapter\UnsupportedClause;
use Orion\Query\Direction;
use Orion\Query\Filter;
use Orion\Query\Fusion;
use Orion\Query\Query;
use Orion\Schema\FieldType;
use Orion\Solr\QueryCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QueryCompilerTest extends TestCase
{
    public function testEverySearchExcludesTombstonesAndReadsTheSource(): void
    {
        $params = (new QueryCompiler())->compile(Query::on('products'));

        self::assertSame('*:*', $params['q']);
        self::assertSame([QueryCompiler::LIVE], $params['fq']);
        self::assertSame(QueryCompiler::FIELDS, $params['fl']);
        self::assertSame(['0', '20'], [$params['start'], $params['rows']]);
    }

    public function testValuesAreBoundAsParametersNeverSplicedIntoSyntax(): void
    {
        $params = (new QueryCompiler())->compile(Query::on('products')->term('sku', 'x} OR {!join from=id to=id}*:*'));

        self::assertSame('{!term f=sku v=$o1}', $params['q']);
        self::assertSame('x} OR {!join from=id to=id}*:*', $params['o1']);
    }

    public function testClausesAreConjunctive(): void
    {
        $params = (new QueryCompiler())->compile(Query::on('products')->match('title', 'running')->prefix('sku', 'A')->phrase('title', 'trail shoe'));

        self::assertSame('{!bool must=$o2 must=$o4 must=$o6}', $params['q']);
        self::assertSame('{!dismax qf=title mm=1 v=$o1}', $params['o2']);
        self::assertSame('{!prefix f=sku v=$o3}', $params['o4']);
        self::assertSame('{!field f=title v=$o5}', $params['o6']);
        self::assertSame(['running', 'A', 'trail shoe'], [$params['o1'], $params['o3'], $params['o5']]);
    }

    public function testMatchTextIsPlainTextNotQuerySyntax(): void
    {
        $params = (new QueryCompiler())->compile(Query::on('products')->match('title', '"trail" +shoe -boot'));

        self::assertSame(' trail   shoe  boot', $params['o1']);
    }

    public function testFiltersAreFilterQueries(): void
    {
        $params = (new QueryCompiler())->compile(Query::on('products')
            ->filter(Filter::term('in_stock', true))
            ->filter(Filter::terms('brand', ['acme', 'globex']))
            ->filter(Filter::exists('stock')));

        self::assertSame([QueryCompiler::LIVE, '{!term f=in_stock v=$o1}', '{!bool should=$o3 should=$o5}', 'stock:[* TO *]'], $params['fq']);
        self::assertSame(['true', '{!term f=brand v=$o2}', 'acme', '{!term f=brand v=$o4}', 'globex'], [$params['o1'], $params['o3'], $params['o2'], $params['o5'], $params['o4']]);
    }

    /**
     * @return iterable<string, array{Filter, string}>
     */
    public static function bools(): iterable
    {
        yield 'must and must not' => [Filter::bool(must: [Filter::term('a', 'x')], mustNot: [Filter::term('b', 'y')]), '{!bool must=$o2 must_not=$o4}'];
        yield 'should alone needs one' => [Filter::bool(should: [Filter::term('a', 'x'), Filter::term('b', 'y')]), '{!bool should=$o2 should=$o4}'];
        yield 'should beside must still needs one' => [Filter::bool(must: [Filter::term('a', 'x')], should: [Filter::term('b', 'y')]), '{!bool must=$o2 must=$o5}'];
        yield 'must not alone excludes from everything' => [Filter::bool(mustNot: [Filter::term('a', 'x')]), '{!bool must=$o3 must_not=$o2}'];
    }

    #[DataProvider('bools')]
    public function testBoolFiltersKeepTheirMeaning(Filter $filter, string $expected): void
    {
        $params = (new QueryCompiler())->compile(Query::on('products')->filter($filter));

        self::assertSame($expected, $params['fq'][1]);
    }

    public function testShouldBesideMustIsWrappedSoItStillFilters(): void
    {
        $params = (new QueryCompiler())->compile(Query::on('products')->filter(Filter::bool(must: [Filter::term('a', 'x')], should: [Filter::term('b', 'y')])));

        self::assertSame('{!bool should=$o4}', $params['o5']);
    }

    public function testMustNotAloneStartsFromEverything(): void
    {
        $params = (new QueryCompiler())->compile(Query::on('products')->filter(Filter::bool(mustNot: [Filter::term('a', 'x')])));

        self::assertSame('*:*', $params['o3']);
    }

    public function testRangesQuoteStringsAndReadDatesAsInstants(): void
    {
        $compiler = new QueryCompiler();
        $query = Query::on('products')
            ->filter(Filter::range('price', gte: 90, lt: 150.5))
            ->filter(Filter::range('released', gt: '2026-03-01'))
            ->filter(Filter::range('sku', lte: 'B"2\\'));

        self::assertTrue($compiler->needsTypes($query));
        $params = $compiler->compile($query, ['released' => FieldType::Date, 'sku' => FieldType::Keyword]);

        self::assertIsArray($params['fq']);
        self::assertSame(['{!lucene v=$o1}', '{!lucene v=$o2}', '{!lucene v=$o3}'], array_slice($params['fq'], 1));
        self::assertSame('price:[90 TO 150.5}', $params['o1']);
        self::assertSame('released:{"2026-03-01T00:00:00Z" TO *]', $params['o2']);
        self::assertSame('sku:[* TO "B\"2\\\\"]', $params['o3']);
        self::assertFalse($compiler->needsTypes(Query::on('products')->filter(Filter::range('price', gte: 1))));
    }

    public function testAnUnreadableDateBoundIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new QueryCompiler())->compile(Query::on('products')->filter(Filter::range('released', gte: 'soon')), ['released' => FieldType::Date]);
    }

    public function testSortsFacetsHighlightsAndPages(): void
    {
        $params = (new QueryCompiler())->compile(Query::on('products')->match('title', 'boot')
            ->sort('price', Direction::Desc)->sort('sku')
            ->facet('brand', 5)
            ->highlight('title')
            ->paginate(3, 10));

        self::assertSame('price desc,sku asc', $params['sort']);
        self::assertSame(['true', ['brand'], '5', '1'], [$params['facet'], $params['facet.field'], $params['f.brand.facet.limit'], $params['facet.mincount']]);
        self::assertSame(['true', 'title', 'unified', 'html', '<em>', '</em>'], [$params['hl'], $params['hl.fl'], $params['hl.method'], $params['hl.encoder'], $params['hl.tag.pre'], $params['hl.tag.post']]);
        self::assertSame(['20', '10'], [$params['start'], $params['rows']]);
    }

    public function testVectorSearchIsKnnWithTheFiltersAsPreFilters(): void
    {
        $params = (new QueryCompiler())->compile(Query::on('products')->vector('embedding', [1.0, 0.0, 0.5], 2)->filter(Filter::term('brand', 'acme')));

        self::assertSame('{!knn f=embedding topK=2 v=$o1}', $params['q']);
        self::assertSame('[1.0,0.0,0.5]', $params['o1']);
        self::assertSame('{!term f=brand v=$o2}', $params['fq'][1]);
    }

    public function testHybridRetrievalIsLeftToCore(): void
    {
        $this->expectException(UnsupportedClause::class);

        (new QueryCompiler())->compile(Query::on('products')->match('title', 'x')->vector('embedding', [1.0], 1)->fuse(Fusion::reciprocalRank()));
    }

    public function testTheSameQueryCompilesToTheSameParameters(): void
    {
        $query = Query::on('products')->match('title', 'x')->filter(Filter::bool(should: [Filter::term('a', 'b')]));

        self::assertSame((new QueryCompiler())->compile($query), (new QueryCompiler())->compile($query));
    }
}
