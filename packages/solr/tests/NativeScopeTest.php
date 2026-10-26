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
        // The security review's bypasses of a parser deny-list, each verified as an oracle on Solr 9 and 10.
        yield 'a join nested in a quoted value' => [['q' => "{!lucene v='{!join from=brand to=brand}title:secret'}"]];
        yield 'a parser named through a parameter' => [['q' => '{!type=$t from=brand to=brand v="title:secret"}', 't' => 'join']];
        yield 'an escaped nested query' => [['q' => '_query_:"{\\!join from=brand to=brand}title:secret"']];
        yield 'an escaped join from another collection' => [['q' => '{\\!join method=crossCollection fromIndex=other from=brand to=brand}title:secret']];
        yield 'a nested query magic field' => [['q' => 'title:x OR _QUERY_:"title:y"']];
        yield 'a function magic field' => [['q' => '_val_:"numdocs()"']];
        yield 'a rerank query' => [['q' => '*:*', 'rq' => '{!rerank reRankQuery=$rrq}', 'rrq' => 'title:x']];
        yield 'a function sort over a nested query' => [['q' => '*:*', 'sort' => "query({!lucene v='title:x'}) desc"]];
        yield 'a highlight query' => [['q' => '*:*', 'hl' => 'true', 'hl.q' => '{!join from=a to=b}*:*']];
        yield 'any local parameters' => [['q' => '{!knn f=embedding topK=5}[1.0,0.0]']];
        yield 'document frequency across tenants' => [['q' => '*:*', 'defType' => 'edismax', 'bf' => "docfreq(title,'secret')"]];
        yield 'collection statistics in a sort' => [['q' => '*:*', 'sort' => 'numdocs() desc']];
        yield 'term statistics in a boost' => [['q' => '*:*', 'defType' => 'edismax', 'boost' => 'ttf(title,secret)']];
        yield 'a function parser by default' => [['q' => "docfreq(title,'secret')", 'defType' => 'func']];
        yield 'a parameter Solr reads by its camel-case name' => [['q' => '*:*', 'useParams' => 'reveal']];
        yield 'more rows than a page' => [['q' => '*:*', 'rows' => 1001]];
        yield 'a parser chosen for the highlight query' => [['q' => '*:*', 'hl' => 'true', 'hl.q' => 'title:x', 'hl.qparser' => 'join']];
        yield 'a magic field spelled in unicode escapes' => [['q' => '_va\\u006c_:"numdocs()"']];
        yield 'a rerank parameter' => [['q' => '*:*', 'rq' => 'title:x']];
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
        $native = ['q' => 'boot OR title:"trail running"', 'defType' => 'edismax', 'qf' => 'title^2 sku', 'bf' => 'recip(ms(NOW,released),3.16e-11,1,1)', 'sort' => 'price asc,score desc', 'start' => 10, 'rows' => 10, 'hl' => 'true', 'hl.fl' => 'title', 'mm' => '1', 'note' => 'mine'];

        $params = NativeScope::request($native, ['{!term f=org_id v=$orion_scope1}'], ['orion_scope1' => 'acme']);

        self::assertSame('mine', $params['note']);
        self::assertSame('10', $params['start']);
    }

    public function testANativeRequestIsParameters(): void
    {
        $this->expectException(LogicException::class);

        NativeScope::request(['q' => ['nested' => ['x']]], [], []);
    }
}
