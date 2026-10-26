<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Support;

use Altair\Persistence\Configuration\DatabaseConnectionFactory;
use Altair\Persistence\Configuration\DatabaseSettings;
use Cycle\Database\DatabaseInterface;
use RuntimeException;

use function explode;
use function getenv;
use function is_string;
use function str_contains;
use function strstr;
use function substr;

/**
 * Cycle connections to the local services, from the same environment the pdo suites read (`.env.testing`).
 */
final class Databases
{
    public const string SQLITE = 'sqlite';
    public const string POSTGRES = 'postgres';
    public const string MYSQL = 'mysql';

    /** The variable whose absence skips a database's suite. */
    public static function variable(string $driver): ?string
    {
        return match ($driver) {
            self::POSTGRES => 'ORION_PGSQL_DSN',
            self::MYSQL => 'ORION_MYSQL_DSN',
            default => null,
        };
    }

    public static function settings(string $driver, ?string $sqliteFile = null): DatabaseSettings
    {
        if ($driver === self::SQLITE) {
            return new DatabaseSettings(DatabaseSettings::DRIVER_SQLITE, $sqliteFile !== null && $sqliteFile !== '' ? $sqliteFile : throw new RuntimeException('A SQLite file is needed.'));
        }
        $dsn = (string) getenv((string) self::variable($driver));
        $parts = self::parts($dsn);
        $user = $driver === self::MYSQL ? (string) getenv('ORION_MYSQL_USER') : ($parts['user'] ?? '');
        $password = $driver === self::MYSQL ? (string) getenv('ORION_MYSQL_PASSWORD') : ($parts['password'] ?? '');

        $database = $parts['dbname'] ?? '';

        return new DatabaseSettings(
            $driver === self::MYSQL ? DatabaseSettings::DRIVER_MYSQL : DatabaseSettings::DRIVER_POSTGRES,
            $database !== '' ? $database : throw new RuntimeException('The DSN names no database.'),
            $parts['host'] ?? '127.0.0.1',
            (int) ($parts['port'] ?? 0),
            $user,
            $password,
        );
    }

    public static function connect(DatabaseSettings $settings): DatabaseInterface
    {
        return (new DatabaseConnectionFactory())->create($settings)->database();
    }

    /**
     * @return array<string, string> `key=value` pairs of a PDO DSN
     */
    private static function parts(string $dsn): array
    {
        $parts = [];
        $body = is_string(strstr($dsn, ':')) ? substr((string) strstr($dsn, ':'), 1) : $dsn;
        foreach (explode(';', $body) as $pair) {
            if (str_contains($pair, '=')) {
                [$key, $value] = explode('=', $pair, 2);
                $parts[$key] = $value;
            }
        }

        return $parts;
    }
}
