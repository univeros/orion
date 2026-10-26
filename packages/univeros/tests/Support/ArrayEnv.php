<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Support;

use Altair\Configuration\Support\Env;

/**
 * An environment of exactly these values, so a test never reads the process's.
 */
final class ArrayEnv extends Env
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(private readonly array $values)
    {
    }

    public function get($name, mixed $default = null): mixed
    {
        return $this->values[$name] ?? $default;
    }
}
