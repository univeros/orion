<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Migrations;

use Altair\Container\Container;
use Altair\Module\ModuleConfiguration;
use Altair\Persistence\Configuration\DatabaseConnectionFactory;
use Altair\Persistence\Migrations\MigrationConfigFactory;
use Altair\Persistence\Migrations\MigratorFactory;
use Altair\Persistence\Migrations\ModuleMigrationDirectories;
use Cycle\Database\DatabaseInterface;
use Cycle\Migrations\Migrator;
use Cycle\Migrations\State;
use Orion\Storage\Schema;
use Orion\Storage\Table;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Univeros\Orion\Database\CycleInstaller;
use Univeros\Orion\Module;
use Univeros\Orion\Tests\Support\Databases;

use function array_map;
use function getenv;
use function getmypid;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The module's migration through Cycle's {@see Migrator}, as `bin/altair db:migrate` runs it: discovered through the
 * container, applied once, every Orion table installed, rolled back.
 */
final class InstallOrionTest extends TestCase
{
    private string $file;
    private string $hostMigrations;
    private DatabaseInterface $database;
    private Migrator $migrator;

    /**
     * SQLite always; PostgreSQL and MySQL when their environment is set.
     *
     * @return iterable<string, array{string}>
     */
    public static function databases(): iterable
    {
        foreach ([Databases::SQLITE, Databases::POSTGRES, Databases::MYSQL] as $driver) {
            $variable = Databases::variable($driver);
            if ($variable === null || getenv($variable) !== false) {
                yield $driver => [$driver];
            }
        }
    }

    protected function tearDown(): void
    {
        while ($this->migrator->rollback() !== null) {
        }
        if (is_file($this->file)) {
            unlink($this->file);
        }
        @rmdir($this->hostMigrations);
    }

    #[DataProvider('databases')]
    public function testDbMigrateInstallsOrionOnceAndRollbackDropsIt(string $driver): void
    {
        $this->migrate($driver);

        $pending = $this->migrator->getMigrations();
        self::assertCount(1, $pending);
        self::assertSame('install_orion', $pending[0]->getState()->getName());
        self::assertSame(State::STATUS_PENDING, $pending[0]->getState()->getStatus());

        self::assertSame(State::STATUS_EXECUTED, $this->migrator->run()?->getState()->getStatus());
        foreach (self::tables() as $table) {
            self::assertTrue($this->database->hasTable($table), $table . ' was not installed.');
        }
        self::assertNull($this->migrator->run(), 'Applied once: a second db:migrate has nothing to do.');

        self::assertNotNull($this->migrator->rollback());
        foreach (self::tables() as $table) {
            self::assertFalse($this->database->hasTable($table), $table . ' survived the rollback.');
        }
    }

    private function migrate(string $driver): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'orion-migration-');
        $this->hostMigrations = sys_get_temp_dir() . '/orion-host-migrations-' . getmypid();
        @mkdir($this->hostMigrations);
        $container = new Container();
        (new ModuleConfiguration([new Module()]))->apply($container);
        $config = (new MigrationConfigFactory())->create($this->hostMigrations, vendorDirectories: ModuleMigrationDirectories::existingFor($container));
        $manager = (new DatabaseConnectionFactory())->create(Databases::settings($driver, $this->file));
        $this->database = $manager->database();
        // A shared server keeps what earlier runs left: start from no Orion tables and no migration record.
        CycleInstaller::uninstall($this->database);
        $this->database->execute('DROP TABLE IF EXISTS ' . $this->database->getDriver()->getQueryCompiler()->quoteIdentifier('cycle_migrations'));
        $this->migrator = (new MigratorFactory())->create($manager, $config);
    }

    /**
     * @return list<non-empty-string>
     */
    private static function tables(): array
    {
        return array_map(static fn(Table $table): string => \Univeros\Orion\Database\CycleExecutor::statement($table->name), Schema::tables());
    }
}
