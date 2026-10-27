<?php

declare(strict_types=1);

namespace Orion\Psr15\Tests;

use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Solr\SolrEngine;
use Orion\Testing\Endpoint\EndpointFixtureTestCase;

use function getenv;

/**
 * The endpoint fixtures recorded in memory, replayed on SolrCloud 10 (ORION_SOLR_URL).
 */
class SolrFixtureTest extends EndpointFixtureTestCase
{
    protected static function urlVariable(): string
    {
        return 'ORION_SOLR_URL';
    }

    protected static function harnessClass(): string
    {
        return PipelineHarness::class;
    }

    protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        $url = getenv(static::urlVariable());
        if ($url === false) {
            self::markTestSkipped(static::urlVariable() . ' is not set.');
        }

        return SolrEngine::connect($url);
    }
}
