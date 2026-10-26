<?php

declare(strict_types=1);

namespace Univeros\Orion\Messaging;

use Orion\IndexName;
use Orion\Wiring\Graph;

/**
 * Runs the drain the bus was asked for. Registered by the module in the framework's handler registry.
 */
final readonly class DrainIndexHandler
{
    public function __construct(private Graph $graph)
    {
    }

    /**
     * Anyone who can put a message on the bus can name an index: one Orion has not built is ignored, so a message
     * cannot make the drain report on, or measure, indices that do not exist.
     */
    public function __invoke(DrainIndex $message): void
    {
        $index = IndexName::of($message->index);
        if ($this->graph->aliases()->current($index) !== null) {
            $this->graph->drain()->run($index);
        }
    }
}
