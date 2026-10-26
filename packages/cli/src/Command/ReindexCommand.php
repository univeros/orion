<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use Orion\Cli\Context;
use Orion\Cli\Result;
use Orion\Lifecycle\IndexNamer;
use Orion\Lifecycle\Reindexer;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * `orion reindex <index> --schema=<file>`: zero-downtime rebuild by outbox replay, resuming an interrupted one;
 * `--abort` gives up an interrupted reindex.
 */
final class ReindexCommand extends OrionCommand
{
    protected function define(): void
    {
        $this->setName('reindex')
            ->setDescription('Rebuild the index from the outbox with the schema and swap the alias, with a receipt')
            ->addArgument('index', InputArgument::REQUIRED, 'The index (alias)')
            ->withSchema()
            ->withEngine()
            ->withDryRun()
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Entries per replay batch', (string) Reindexer::DEFAULT_BATCH)
            ->addOption('abort', null, InputOption::VALUE_NONE, 'Give up the interrupted reindex: drop the version it was building');
    }

    protected function handle(InputInterface $input): Result
    {
        $graph = $this->context->graph($input);
        $index = Context::index($input);
        $engine = Context::engine($input, $graph, $index);
        $reindexer = $graph->reindexer($engine);
        if ($input->getOption('abort') === true) {
            return new Result(['index' => $index->value, 'aborted' => $reindexer->abort($index)?->physical->value]);
        }
        $schema = Context::declared($input, $graph, $index);
        $batch = Context::count($input->getOption('batch'), 'batch', 1);
        if ($input->getOption('dry-run') === true) {
            $pending = $reindexer->pending($index);
            $plan = $graph->planner($engine)->plan($schema);

            return new Result([
                'index' => $index->value,
                'engine' => $engine,
                'dry_run' => true,
                'requires_reindex' => $plan->requiresReindex(),
                'plan' => $plan->toArray(),
                'pending' => $pending?->toArray(),
                'next' => ($pending === null ? IndexNamer::physical($index, $graph->registry()->nextVersion($index)) : $pending->physical)->value,
                'entries' => $graph->outbox()->size($index),
            ]);
        }

        return new Result($reindexer->reindex($schema, $batch)->toArray());
    }
}
