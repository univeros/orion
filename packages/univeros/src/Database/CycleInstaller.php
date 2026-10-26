<?php

declare(strict_types=1);

namespace Univeros\Orion\Database;

use Cycle\Database\DatabaseInterface;
use Orion\Pdo\SqlSchema;
use Orion\Storage\Schema;
use Orion\Storage\Table;

/**
 * Orion's tables on a Cycle connection, as data ({@see Schema}) turned into the dialect's DDL by `orion-search/pdo`.
 * A table that exists is left as it is, so installing twice is safe. The module's migration calls it inside the
 * transaction Cycle's migrator opens.
 */
final class CycleInstaller
{
    /**
     * @param list<Table>|null $tables Orion's own when null
     * @return list<string> the tables created, in order
     */
    public static function install(DatabaseInterface $database, ?array $tables = null): array
    {
        $dialect = (new CycleExecutor($database))->dialect();
        $created = [];
        foreach ($tables ?? Schema::tables() as $table) {
            if ($database->hasTable(CycleExecutor::statement($table->name))) {
                continue;
            }
            foreach (SqlSchema::create($table, $dialect) as $statement) {
                $database->execute(CycleExecutor::statement($statement));
            }
            $created[] = $table->name;
        }

        return $created;
    }

    /**
     * @param list<Table>|null $tables Orion's own when null
     */
    public static function uninstall(DatabaseInterface $database, ?array $tables = null): void
    {
        $dialect = (new CycleExecutor($database))->dialect();
        foreach ($tables ?? Schema::tables() as $table) {
            $database->execute(CycleExecutor::statement(SqlSchema::drop($table, $dialect)));
        }
    }
}
