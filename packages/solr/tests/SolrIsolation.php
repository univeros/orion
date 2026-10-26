<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Testing\TenancyIsolationTestCase;

/**
 * Runs the tenancy isolation case against the SolrCloud node in {@see urlVariable()}; skipped when it is unset.
 */
abstract class SolrIsolation extends TenancyIsolationTestCase
{
    abstract protected static function urlVariable(): string;

    protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return SolrServer::engine(static::urlVariable(), $this);
    }

    protected function nativeMatchAll(): callable
    {
        return static fn(): array => ['q' => '*:*', 'rows' => 100];
    }

    protected function nativeShapes(): array
    {
        return [
            'foreign ids' => static fn(): array => ['q' => 'id:"globex:p1" OR id:"globex:p4"', 'rows' => 100],
            'the foreign tenant by name' => static fn(): array => ['q' => 'org_id:globex', 'rows' => 100],
            'disjunction with everything' => static fn(): array => ['q' => 'org_id:globex OR *:*', 'rows' => 100],
            'edismax over the foreign title' => static fn(): array => ['q' => 'globex trail running', 'defType' => 'edismax', 'qf' => 'title', 'rows' => 100],
            'own filter on the foreign tenant' => static fn(): array => ['q' => '*:*', 'fq' => 'org_id:globex', 'rows' => 100],
        ];
    }

    protected function nativeScopeEscapes(): array
    {
        return [
            'facets on the org key' => static fn(): array => ['q' => '*:*', 'facet' => 'true', 'facet.field' => 'org_id'],
            'json facets' => static fn(): array => ['q' => '*:*', 'json.facet' => '{"t":{"type":"terms","field":"org_id"}}'],
            'stats' => static fn(): array => ['q' => '*:*', 'stats' => 'true', 'stats.field' => 'stock'],
            'a join on the foreign tenant' => static fn(): array => ['q' => '{!join from=brand to=brand}org_id:globex', 'rows' => 100],
            'a join from another collection' => fn(): array => ['q' => '{!join fromIndex=' . $this->indexName() . ' from=brand to=brand}*:*'],
            'more like a foreign document' => static fn(): array => ['q' => '{!mlt qf=title mintf=1 mindf=1}globex:p1'],
            'the terms component' => static fn(): array => ['terms' => 'true', 'terms.fl' => 'org_id'],
            'a join nested in a quoted value' => static fn(): array => ['q' => "{!lucene v='{!join from=brand to=brand}title:globex'}", 'rows' => 100],
            'a parser named through a parameter' => static fn(): array => ['q' => '{!type=$t from=brand to=brand v="title:globex"}', 't' => 'join', 'rows' => 100],
            'an escaped nested join' => static fn(): array => ['q' => '_query_:"{\\!join from=brand to=brand}title:globex"', 'rows' => 100],
            'an escaped join from another collection' => fn(): array => ['q' => '{\\!join method=crossCollection fromIndex=' . $this->indexName() . ' from=brand to=brand}title:globex', 'rows' => 100],
            'a rerank by a nested join' => static fn(): array => ['q' => '*:*', 'rq' => '{!rerank reRankQuery=$rrq}', 'rrq' => "{!lucene v='{!join from=brand to=brand}title:globex'}"],
            'document frequency across tenants' => static fn(): array => ['q' => '*:*', 'defType' => 'edismax', 'bf' => "docfreq(title,'globex')"],
            'the number of documents' => static fn(): array => ['q' => '*:*', 'sort' => 'numdocs() desc'],
            'another collection' => static fn(): array => ['q' => '*:*', 'collection' => 'other'],
        ];
    }
}
