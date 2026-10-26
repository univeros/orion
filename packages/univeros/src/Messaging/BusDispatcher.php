<?php

declare(strict_types=1);

namespace Univeros\Orion\Messaging;

use Altair\Messaging\Contracts\MessageBusInterface;
use Orion\IndexName;
use Orion\Outbox\Dispatcher;
use Override;

/**
 * Orion's {@see Dispatcher} on the framework bus: the drain runs on the worker, not in the request.
 */
final readonly class BusDispatcher implements Dispatcher
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    #[Override]
    public function dispatch(IndexName $index): void
    {
        $this->bus->dispatch(new DrainIndex($index->value));
    }
}
