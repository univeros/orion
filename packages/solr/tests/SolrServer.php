<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

use Orion\Solr\SolrEngine;
use PHPUnit\Framework\TestCase;

use function getenv;

/**
 * The SolrCloud node the service-backed suites run against: ORION_SOLR_URL (Solr 10) or ORION_SOLR9_URL (Solr 9).
 */
final class SolrServer
{
    public static function engine(string $variable, TestCase $test): SolrEngine
    {
        $url = getenv($variable);
        if ($url === false) {
            $test->markTestSkipped($variable . ' is not set.');
        }

        return SolrEngine::connect($url);
    }

    /**
     * Native shapes that would match beyond a scope, in Solr's request parameters.
     *
     * @return array<string, callable(): array<string, mixed>>
     */
    public static function shapes(): array
    {
        return [
            'disjunction with everything' => static fn(): array => ['q' => 'title:running OR *:*', 'rows' => 100],
            'boosted function' => static fn(): array => ['q' => '{!boost b=1}*:*', 'rows' => 100],
            'knn' => static fn(): array => ['q' => '{!knn f=embedding topK=5}[1.0,0.0,0.0]', 'rows' => 100],
            'own filter query' => static fn(): array => ['q' => '*:*', 'fq' => 'sku:[* TO *]', 'rows' => 100],
            'own parameter' => static fn(): array => ['q' => '{!lucene v=$all}', 'all' => '*:*', 'rows' => 100],
        ];
    }

    /**
     * Native requests that read across the collection and must be refused under a scope.
     *
     * @return array<string, callable(): array<string, mixed>>
     */
    public static function escapes(): array
    {
        return [
            'facets' => static fn(): array => ['q' => '*:*', 'rows' => 0, 'facet' => 'true', 'facet.field' => 'brand'],
            'json facets' => static fn(): array => ['q' => '*:*', 'json.facet' => '{"b":{"type":"terms","field":"brand"}}'],
            'stats' => static fn(): array => ['q' => '*:*', 'stats' => 'true', 'stats.field' => 'price'],
            'a join' => static fn(): array => ['q' => '{!join from=brand to=brand}*:*'],
        ];
    }
}
