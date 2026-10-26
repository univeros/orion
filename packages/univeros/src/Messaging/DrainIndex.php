<?php

declare(strict_types=1);

namespace Univeros\Orion\Messaging;

/**
 * "Drain this index", sent on the bus after a commit that recorded into its outbox.
 */
final readonly class DrainIndex
{
    public function __construct(public string $index)
    {
    }
}
