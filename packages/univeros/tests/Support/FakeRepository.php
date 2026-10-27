<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Support;

use Override;
use Polaris\Contract\RepositoryInterface;

use function array_filter;
use function array_values;

/**
 * A Polaris repository over objects in memory, matched on their public properties: enough for the permission resolver
 * behind a real `Gate`.
 *
 * @template T of object
 * @implements RepositoryInterface<T>
 */
final readonly class FakeRepository implements RepositoryInterface
{
    /**
     * @param list<T> $rows
     */
    public function __construct(private array $rows = [])
    {
    }

    #[Override]
    public function find(int|string $id): ?object
    {
        return $this->findOneBy(['id' => $id]);
    }

    #[Override]
    public function findOneBy(array $criteria): ?object
    {
        return array_values($this->findBy($criteria))[0] ?? null;
    }

    /**
     * @return list<T>
     */
    #[Override]
    public function findBy(array $criteria): array
    {
        return array_values(array_filter($this->rows, static function (object $row) use ($criteria): bool {
            foreach ($criteria as $property => $value) {
                if (($row->{$property} ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @return list<T>
     */
    #[Override]
    public function findAll(): array
    {
        return $this->rows;
    }
}
