<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Harness;

use Orion\Storage\Storage;
use Univeros\Orion\Database\CycleInstaller;
use Univeros\Orion\Database\CycleStorage;
use Univeros\Orion\Tests\Support\Databases;

use function sys_get_temp_dir;
use function tempnam;

/**
 * The storage the module builds, on a fresh SQLite file through the framework's Cycle connection factory.
 */
final class CycleStorageFactory
{
    public static function fresh(): Storage
    {
        $database = Databases::connect(Databases::settings(Databases::SQLITE, (string) tempnam(sys_get_temp_dir(), 'orion-harness-')));
        CycleInstaller::install($database);

        return CycleStorage::on($database);
    }
}
