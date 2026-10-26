<?php

declare(strict_types=1);

namespace Univeros\Orion\Database;

use Cycle\Database\DatabaseInterface;
use Cycle\Database\Injection\Parameter;
use Cycle\Database\StatementInterface;
use InvalidArgumentException;
use Orion\Pdo\Dialect;
use Orion\Pdo\Executor;
use Orion\Pdo\PdoExecutor;
use Override;
use PDO;
use Throwable;

use function array_map;
use function is_bool;
use function is_int;
use function sprintf;

/**
 * Orion's SQL on the host's Cycle connection (docs/spec/revision.md §4): the same `DatabaseInterface` the ORM writes
 * entities through, so an outbox entry commits or rolls back with them. Transactions go through Cycle, which keeps
 * one transaction per connection and nests as savepoints, so Orion joins the host's unit of work instead of opening
 * a second one. Values are bound with their PDO types; Cycle would bind everything as a string.
 */
final readonly class CycleExecutor implements Executor
{
    private Dialect $dialect;

    public function __construct(private DatabaseInterface $database)
    {
        $type = $database->getDriver()->getType();
        $this->dialect = match ($type) {
            'Postgres' => Dialect::Postgres,
            'MySQL' => Dialect::Mysql,
            'SQLite' => Dialect::Sqlite,
            default => throw new InvalidArgumentException(sprintf('Orion runs on PostgreSQL, MySQL and SQLite; the Cycle driver is %s.', $type)),
        };
    }

    #[Override]
    public function dialect(): Dialect
    {
        return $this->dialect;
    }

    #[Override]
    public function select(string $sql, array $params): array
    {
        /** @var list<array<string, mixed>> $rows */
        // The write driver, never a read replica: Orion reads what its own transaction wrote (an offset, a lease).
        $rows = $this->database->getDriver(DatabaseInterface::WRITE)->query(self::statement($sql), self::typed($params))->fetchAll(StatementInterface::FETCH_ASSOC);

        return $rows;
    }

    #[Override]
    public function execute(string $sql, array $params): int
    {
        return $this->database->execute(self::statement($sql), self::typed($params));
    }

    #[Override]
    public function transaction(callable $fn): mixed
    {
        return $this->database->transaction(static fn(): mixed => $fn());
    }

    #[Override]
    public function isDuplicate(Throwable $failure): bool
    {
        return PdoExecutor::isUniqueViolation($failure);
    }

    /**
     * @return non-empty-string
     */
    public static function statement(string $sql): string
    {
        return $sql !== '' ? $sql : throw new InvalidArgumentException('An empty SQL statement.');
    }

    /**
     * @param list<string|int|bool|null> $params
     * @return list<Parameter>
     */
    private static function typed(array $params): array
    {
        return array_map(static fn(string|int|bool|null $value): Parameter => new Parameter($value, match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        }), $params);
    }
}
