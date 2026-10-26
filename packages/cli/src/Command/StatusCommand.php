<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use Orion\Adapter\Capability;
use Orion\Cli\Context;
use Orion\Cli\Output;
use Orion\Cli\Result;
use Orion\Registry\IndexRegistration;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

use function array_map;

/**
 * `orion status <index>`: the current version and every registered one, the last swap, the outbox and drain, dead
 * letters, a reindex in progress, receipts issued and the engine's capabilities.
 */
final class StatusCommand extends OrionCommand
{
    protected function define(): void
    {
        $this->setName('status')
            ->setDescription('Describe an index: versions, alias, outbox lag, dead letters, reindex, receipts, capabilities')
            ->addArgument('index', InputArgument::REQUIRED, 'The index (alias)');
    }

    protected function handle(InputInterface $input): Result
    {
        $graph = $this->context->graph($input);
        $index = Context::index($input);
        $current = $graph->aliases()->current($index);
        $drain = $graph->drain()->status($index);

        return new Result([
            'index' => $index->value,
            'engine' => $current?->engine,
            'current' => $current === null ? null : ['physical' => $current->physical->value, 'version' => $current->version, 'schema_hash' => $current->schema->hash()],
            'versions' => array_map(static fn(IndexRegistration $r): array => [
                'physical' => $r->physical->value,
                'version' => $r->version,
                'schema_hash' => $r->schema->hash(),
                'created_at' => Output::time($r->createdAt),
            ], $graph->registry()->versions($index)),
            'swapped_at' => Output::time($graph->aliases()->swappedAt($index)),
            'outbox' => ['head' => $drain->head, 'drained_to' => $drain->to, 'lag' => $drain->lag, 'dead_letters' => $graph->deadLetters()->count($index)],
            'reindex' => $current === null ? null : $graph->reindexer($current->engine)->pending($index)?->toArray(),
            'receipts' => $graph->receipts()->count($index),
            'capabilities' => $current === null ? null : CapabilitiesCommand::of($graph->engines()->get($current->engine)),
        ]);
    }
}
