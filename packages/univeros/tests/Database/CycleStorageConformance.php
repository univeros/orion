<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Database;

use Cycle\Database\DatabaseInterface;
use Orion\Storage\Storage;
use Orion\Testing\StorageConformanceTestCase;
use Throwable;
use Univeros\Orion\Database\CycleInstaller;
use Univeros\Orion\Database\CycleStorage;

/**
 * The storage conformance case on the host's Cycle connection: entity rows and the host's transaction go through
 * Cycle's own API, as an application's repositories and unit of work would, and Orion's rows through the module's
 * storage on the same `DatabaseInterface`. The same-connection tests are the WP5 acceptance criterion.
 */
abstract class CycleStorageConformance extends StorageConformanceTestCase
{
    protected DatabaseInterface $database;

    abstract protected function connect(): DatabaseInterface;

    protected function createStorage(): Storage
    {
        $this->database = $this->connect();
        CycleInstaller::uninstall($this->database, self::tables());
        CycleInstaller::install($this->database, self::tables());

        return CycleStorage::on($this->database);
    }

    protected function insertOnHostConnection(string $table, array $row): void
    {
        $this->database->insert($table)->values($row)->run();
    }

    protected function inHostTransaction(callable $work): void
    {
        $this->database->begin();
        try {
            $work();
            $this->database->commit();
        } catch (Throwable $exception) {
            $this->database->rollback();

            throw $exception;
        }
    }
}
