<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Harness;

use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Storage\Storage;
use Orion\Testing\FaultInjectionTestCase;

/**
 * The fault-injection harness with Orion's tables on the module's Cycle storage: a database dying mid-swap is now a
 * real Cycle transaction rolling back.
 */
final class CycleFaultInjectionTest extends FaultInjectionTestCase
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
