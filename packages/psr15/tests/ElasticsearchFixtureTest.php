<?php

declare(strict_types=1);

namespace Orion\Psr15\Tests;

use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Elasticsearch\ElasticsearchEngine;
use Orion\Testing\Endpoint\EndpointFixtureTestCase;

use function getenv;

/**
 * The endpoint fixtures recorded in memory, replayed on Elasticsearch 9 (ORION_ELASTICSEARCH_URL): the endpoint
 * answers the same whatever the engine.
 */
class ElasticsearchFixtureTest extends EndpointFixtureTestCase
{
    protected static function urlVariable(): string
    {
        return 'ORION_ELASTICSEARCH_URL';
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

        return ElasticsearchEngine::connect($url);
    }
}
