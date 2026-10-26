<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Database;

use Orion\Wiring\Config;
use Orion\Tenancy\Tenancy;
use Orion\Registry\EngineRegistry;
use Orion\Orion;
use Orion\Adapter\InMemory\InMemoryEngine;
use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLite\FileConnectionConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseManager;
use Orion\IndexName;
use Orion\Outbox\Outbox;
use Orion\Testing\FixedClock;
use PHPUnit\Framework\TestCase;
use Univeros\Orion\Database\CycleInstaller;
use Univeros\Orion\Database\CycleStorage;

use function sys_get_temp_dir;
use function tempnam;

/**
 * A host that splits reads to a replica: everything Orion reads must still come from the connection its transaction
 * writes on, or an offset, a lease or a receipt would be read stale (docs/spec/revision.md §4).
 */
final class ReadReplicaTest extends TestCase
{
    public function testOrionReadsOnTheWriteConnectionEvenWhenTheHostSplitsReads(): void
    {
        $manager = new DatabaseManager(new DatabaseConfig([
            'default' => 'default',
            'databases' => ['default' => ['connection' => 'primary', 'readConnection' => 'replica']],
            'connections' => [
                'primary' => new SQLiteDriverConfig(new FileConnectionConfig((string) tempnam(sys_get_temp_dir(), 'orion-primary-'))),
                'replica' => new SQLiteDriverConfig(new FileConnectionConfig((string) tempnam(sys_get_temp_dir(), 'orion-replica-'))),
            ],
        ]));
        $database = $manager->database();
        CycleInstaller::install($database);
        $outbox = Orion::create(new Config(storage: CycleStorage::on($database), engines: new EngineRegistry(memory: new InMemoryEngine()), tenancy: Tenancy::single(), clock: new FixedClock()))->graph()->outbox();

        $database->transaction(static function () use ($outbox): void {
            self::assertSame(1, $outbox->index(IndexName::of('products'), 'p1', ['title' => 'Lamp'])->offset);
            self::assertSame(2, $outbox->index(IndexName::of('products'), 'p2', ['title' => 'Desk'])->offset);
        });

        self::assertSame(2, $outbox->head(IndexName::of('products')));
        self::assertSame(2, $outbox->size(IndexName::of('products')));
    }
}
