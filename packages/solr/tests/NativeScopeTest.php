<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

use InvalidArgumentException;
use LogicException;
use Orion\Solr\NativeScope;
use Orion\Solr\QueryCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeScopeTest extends TestCase
{
    public function testUnscopedTheNativeRequestRunsAsGivenOverLiveDocuments(): void
    {
        $params = NativeScope::request(['q' => 'title:boot', 'facet' => 'true', 'facet.field' => ['brand'], 'rows' => 5], [], []);

        self::assertSame('title:boot', $params['q']);
        self::assertSame('true', $params['facet']);
        self::assertSame(['brand'], $params['facet.field']);
        self::assertSame('5', $params['rows']);
        self::assertSame([QueryCompiler::LIVE], $params['fq']);
        self::assertSame(QueryCompiler::FIELDS, $params['fl']);
    }

    public function testScopedTheFiltersJoinTheNativeOnesAndTheirValuesAreBound(): void
    {
        $params = NativeScope::request(['q' => '*:*', 'fq' => 'in_stock:true', 'fl' => 'secret'], ['{!term f=org_id v=$orion_scope1}'], ['orion_scope1' => 'acme']);

        self::assertSame(['in_stock:true', QueryCompiler::LIVE, '{!term f=org_id v=$orion_scope1}'], $params['fq']);
        self::assertSame('acme', $params['orion_scope1']);
        self::assertSame(QueryCompiler::FIELDS, $params['fl'], 'The envelope reads its own fields.');
    }

    public function testANativeRequestCannotRebindTheScopesParameters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        NativeScope::request(['q' => '*:*', 'orion_scope1' => 'globex'], ['{!term f=org_id v=$orion_scope1}'], ['orion_scope1' => 'acme']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function escapes(): iterable
    {
        yield 'facets over the index' => [['q' => '*:*', 'facet' => 'true', 'facet.field' => 'brand']];
        yield 'json facets' => [['q' => '*:*', 'json.facet' => '{"b":{"type":"terms","field":"brand"}}']];
        yield 'the json request api' => [['json' => '{"query":"*:*"}']];
        yield 'stats' => [['q' => '*:*', 'stats' => 'true', 'stats.field' => 'price']];
        yield 'terms component' => [['terms' => 'true', 'terms.fl' => 'org_id']];
        yield 'another collection' => [['q' => '*:*', 'collection' => 'other']];
        yield 'shards' => [['q' => '*:*', 'shards' => 'localhost:8983/solr/other']];
        yield 'a join' => [['q' => '{!join from=id to=id}brand:globex']];
        yield 'a join from another index' => [['q' => '{!join fromIndex=other from=id to=id}*:*']];
        yield 'a join by type' => [['q' => '{!type=join from=id to=id}*:*']];
        yield 'a join with a tag first' => [['fq' => '{!tag=t type=join from=a to=b}*:*', 'q' => '*:*']];
        yield 'a join nested in lucene' => [['q' => 'title:x OR _query_:"{!join from=id to=id}*:*"']];
        yield 'a join behind a parameter' => [['q' => '{!lucene v=$inner}', 'inner' => '{!join from=id to=id}*:*']];
        yield 'a join as the default type' => [['q' => '*:*', 'defType' => 'join']];
        yield 'more like this' => [['q' => '{!mlt qf=title}globex:p1']];
        yield 'graph traversal' => [['q' => '{!graph from=a to=b}*:*']];
        yield 'block join to parents' => [['q' => '{!parent which=type:parent}*:*']];
        yield 'xml query parser' => [['q' => '{!xmlparser}<MatchAllDocsQuery/>']];
        yield 'debugging output' => [['q' => '*:*', 'debugQuery' => 'true']];
        yield 'another request handler' => [['q' => '*:*', 'qt' => '/admin']];
    }

    /**
     * @param array<string, mixed> $native
     */
    #[DataProvider('escapes')]
    public function testAScopedNativeRequestMayNotReadOutsideTheScope(array $native): void
    {
        $this->expectException(InvalidArgumentException::class);

        NativeScope::request($native, ['{!term f=org_id v=$orion_scope1}'], ['orion_scope1' => 'acme']);
    }

    public function testAScopedNativeRequestMayStillQueryAndPage(): void
    {
        $native = ['q' => '{!edismax qf=title v=$text}', 'text' => 'boot', 'defType' => 'lucene', 'sort' => 'price asc', 'start' => 10, 'rows' => 10, 'hl' => 'true', 'hl.fl' => 'title', 'mm' => '1'];

        $params = NativeScope::request($native, ['{!term f=org_id v=$orion_scope1}'], ['orion_scope1' => 'acme']);

        self::assertSame('boot', $params['text']);
        self::assertSame('10', $params['start']);
    }

    public function testANativeRequestIsParameters(): void
    {
        $this->expectException(LogicException::class);

        NativeScope::request(['q' => ['nested' => ['x']]], [], []);
    }
}
