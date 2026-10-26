<?php

declare(strict_types=1);

use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Receipt\SodiumSigner;
use Orion\Registry\EngineRegistry;
use Orion\Tenancy\Tenancy;
use Orion\Testing\FixedClock;
use Orion\Testing\InMemoryStorage;
use Orion\Wiring\Config;

// What an application's bootstrap returns: its Config (or its Orion). Everything here is in memory, so each process
// starts from the same state.
return new Config(
    storage: new InMemoryStorage(),
    engines: new EngineRegistry(memory: new InMemoryEngine()),
    tenancy: Tenancy::single(),
    clock: new FixedClock(new DateTimeImmutable('2026-10-26 12:00:00+00:00')),
    receiptSigner: SodiumSigner::fromSeed(str_repeat("\x04", 32), 'cli'),
);
