<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Harness;

use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Storage\Storage;
use Orion\Testing\LifecycleDeterminismTestCase;

/**
 * The determinism harness with Orion's tables on the module's Cycle storage.
 */
final class CycleLifecycleDeterminismTest extends LifecycleDeterminismTestCase
{
    protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return new InMemoryEngine();
    }

    protected function createStorage(): Storage
    {
        return CycleStorageFactory::fresh();
    }
}
