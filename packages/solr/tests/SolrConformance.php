<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Testing\EngineConformanceTestCase;

/**
 * Runs the engine conformance case against the SolrCloud node in {@see urlVariable()}; skipped when it is unset.
 */
abstract class SolrConformance extends EngineConformanceTestCase
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

    protected function compiledAsNative(array $compiled): ?callable
    {
        return static fn(): mixed => $compiled['params'] ?? null;
    }

    protected function nativeShapes(): array
    {
        return SolrServer::shapes();
    }

    protected function nativeScopeEscapes(): array
    {
        return SolrServer::escapes();
    }
}
