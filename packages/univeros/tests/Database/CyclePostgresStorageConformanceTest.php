<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Database;

use Cycle\Database\DatabaseInterface;
use Univeros\Orion\Tests\Support\Databases;

use function getenv;

final class CyclePostgresStorageConformanceTest extends CycleStorageConformance
{
    use HoldsIncrementLocks;

    protected function setUp(): void
    {
        $variable = (string) Databases::variable(Databases::POSTGRES);
        if (getenv($variable) === false) {
            self::markTestSkipped($variable . ' is not set.');
        }
        parent::setUp();
    }

    protected function limitLockWait(DatabaseInterface $database): void
    {
        $database->execute("SET lock_timeout = '200ms'");
    }

    protected function connect(): DatabaseInterface
    {
        return Databases::connect(Databases::settings(Databases::POSTGRES));
    }
}
