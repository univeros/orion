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
            'foreign ids' => static fn(): array => ['q' => '{!terms f=id}globex:p1,globex:p4', 'rows' => 100],
            'the foreign tenant by name' => static fn(): array => ['q' => '{!term f=org_id v=$tenant}', 'tenant' => 'globex', 'rows' => 100],
            'disjunction with everything' => static fn(): array => ['q' => 'org_id:globex OR *:*', 'rows' => 100],
            'knn at the foreign tenant' => static fn(): array => ['q' => '{!knn f=embedding topK=10}[0.0,1.0,0.0]', 'rows' => 100],
            'own filter on the foreign tenant' => static fn(): array => ['q' => '*:*', 'fq' => 'org_id:globex', 'rows' => 100],
            'collapse' => static fn(): array => ['q' => '*:*', 'fq' => '{!collapse field=brand}', 'rows' => 100],
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
            'another collection' => static fn(): array => ['q' => '*:*', 'collection' => 'other'],
        ];
    }
}
