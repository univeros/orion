<?php

declare(strict_types=1);

namespace Univeros\Orion\Migrations;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Driver\Driver;
use Cycle\Migrations\Migration;
use Univeros\Orion\Database\CycleInstaller;

/**
 * Installs Orion's tables on the application's default Cycle database (the one the module's storage uses), inside the transaction Cycle's migrator wraps around
 * `up()`, so `bin/altair db:migrate` installs them atomically with its own record where the database allows (MySQL
 * commits DDL as it goes).
 */
final class InstallOrion extends Migration
{
    public function up(): void
    {
        CycleInstaller::install($this->settled());
    }

    public function down(): void
    {
        CycleInstaller::uninstall($this->settled());
    }

    /**
     * The migrator's own state queries leave cached statements open on this connection, and on SQLite an open cursor
     * makes `DROP TABLE` fail with "database table is locked": clearing the statement cache finalises them.
     */
    private function settled(): DatabaseInterface
    {
        $database = $this->database();
        $driver = $database->getDriver();
        if ($driver instanceof Driver) {
            $driver->clearCache();
        }

        return $database;
    }
}
