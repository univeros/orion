<?php

declare(strict_types=1);

namespace Orion\Cli;

use Symfony\Component\Console\Command\Command;

/**
 * What a command printed and how it exits.
 */
final readonly class Result
{
    /** A breaking change needs a reindex, or a check found drift: nothing failed, someone must act. */
    public const int ACTION_REQUIRED = 3;

    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(public array $data, public int $code = Command::SUCCESS)
    {
    }
}
