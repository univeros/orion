<?php

declare(strict_types=1);

namespace Univeros\Orion\Database;

use Cycle\Database\DatabaseInterface;
use Orion\Pdo\SqlStorage;

/**
 * Orion's {@see \Orion\Storage\Storage} on a Cycle connection.
 */
final class CycleStorage
{
    public static function on(DatabaseInterface $database): SqlStorage
    {
        return new SqlStorage(new CycleExecutor($database));
    }
}
