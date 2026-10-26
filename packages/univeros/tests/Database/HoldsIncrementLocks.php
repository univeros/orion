<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Database;

use Cycle\Database\DatabaseInterface;
use Orion\Storage\Schema;
use Throwable;
use Univeros\Orion\Database\CycleStorage;

/**
 * Outbox offsets are committed in order only if the counter row stays locked until the host's transaction ends: a
 * second Cycle connection must not take the next value meanwhile. PostgreSQL and MySQL lock the row; SQLite locks
 * the whole database for writers, which serialises them by construction.
 */
trait HoldsIncrementLocks
{
    abstract protected function limitLockWait(DatabaseInterface $database): void;

    public function testAnIncrementHoldsItsRowUntilTheHostsTransactionEnds(): void
    {
        $storage = CycleStorage::on($this->database);
        $storage->insert(Schema::COUNTER, ['name' => 'outbox:products', 'value' => 0]);
        $second = $this->connect();
        $this->limitLockWait($second);
        $other = CycleStorage::on($second);
        $this->inHostTransaction(function () use ($storage, $other): void {
            self::assertSame(1, $storage->increment(Schema::COUNTER, ['name' => 'outbox:products'], 'value'));
            $refused = null;
            try {
                $other->increment(Schema::COUNTER, ['name' => 'outbox:products'], 'value');
            } catch (Throwable $exception) {
                $refused = $exception;
            }
            self::assertNotNull($refused, 'A second connection incremented a row the host\'s transaction holds.');
        });

        self::assertSame(2, $other->increment(Schema::COUNTER, ['name' => 'outbox:products'], 'value'));
    }
}
