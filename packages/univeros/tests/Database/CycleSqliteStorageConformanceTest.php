<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Database;

use Cycle\Database\DatabaseInterface;
use Univeros\Orion\Tests\Support\Databases;

use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class CycleSqliteStorageConformanceTest extends CycleStorageConformance
{
    private ?string $file = null;

    protected function tearDown(): void
    {
        if ($this->file !== null && is_file($this->file)) {
            unlink($this->file);
        }
    }

    protected function connect(): DatabaseInterface
    {
        $this->file ??= (string) tempnam(sys_get_temp_dir(), 'orion-cycle-');

        return Databases::connect(Databases::settings(Databases::SQLITE, $this->file));
    }
}
