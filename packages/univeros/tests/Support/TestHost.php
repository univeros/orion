<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Support;

use Altair\Configuration\Support\Env;
use Altair\Container\Container;
use Altair\Module\ModuleConfiguration;
use Altair\Persistence\Configuration\CycleOrmConfiguration;
use Cycle\Database\DatabaseManager;
use Univeros\Orion\Database\CycleInstaller;
use Univeros\Orion\Module;

use function sys_get_temp_dir;
use function tempnam;

/**
 * A Univeros container as an application boots it: the environment, the framework's Cycle configuration on a SQLite
 * file with Orion's tables installed, then the modules.
 */
final class TestHost
{
    public const array ENVIRONMENT = ['ORION_ENGINE' => 'memory', 'ORION_TENANCY' => 'single'];

    /**
     * @param array<string, string> $env
     * @param (callable(Container): void)|null $before what the application binds before the modules apply
     */
    public static function boot(array $env = self::ENVIRONMENT, ?callable $before = null, bool $database = true): Container
    {
        $container = new Container();
        $values = $env;
        if ($database) {
            $values += ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => (string) tempnam(sys_get_temp_dir(), 'orion-host-')];
        }
        $container->singleton(Env::class, static fn(): Env => new ArrayEnv($values));
        if ($database) {
            (new CycleOrmConfiguration())->apply($container);
            $manager = $container->get(DatabaseManager::class);
            \assert($manager instanceof DatabaseManager);
            CycleInstaller::install($manager->database());
        }
        if ($before !== null) {
            $before($container);
        }
        (new ModuleConfiguration([new Module()]))->apply($container);

        return $container;
    }
}
