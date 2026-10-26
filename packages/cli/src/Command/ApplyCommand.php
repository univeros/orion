<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use Orion\Cli\Context;
use Orion\Cli\Result;
use Orion\Lifecycle\Operation;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

/**
 * `orion apply <index> --schema=<file>`: creates the index or applies additive changes in place, with a receipt; a
 * breaking change touches nothing and exits 3.
 */
final class ApplyCommand extends OrionCommand
{
    protected function define(): void
    {
        $this->setName('apply')
            ->setDescription('Create the index or apply additive changes in place; refuse breaking ones')
            ->addArgument('index', InputArgument::REQUIRED, 'The index (alias)')
            ->withSchema()
            ->withEngine()
            ->withDryRun();
    }

    protected function handle(InputInterface $input): Result
    {
        $graph = $this->context->graph($input);
        $index = Context::index($input);
        $schema = Context::schema($input->getOption('schema'), $index);
        $engine = Context::engine($input, $graph, $index);
        if ($input->getOption('dry-run') === true) {
            $built = $graph->aliases()->current($index) !== null;
            $plan = $graph->planner($engine)->plan($schema);
            $state = Context::state($plan, $built);

            return new Result([
                'index' => $index->value,
                'engine' => $engine,
                'dry_run' => true,
                'would' => match ($state) {
                    'missing' => 'create',
                    'breaking' => 'reindex_required',
                    'in_sync' => $built && $graph->aliases()->current($index)?->schema->hash() !== $schema->hash() ? 'apply' : 'nothing',
                    default => 'apply',
                },
                'plan' => $plan->toArray(),
            ]);
        }
        $outcome = $graph->planner($engine)->apply($schema);

        return new Result($outcome->toArray(), $outcome->operation === Operation::ReindexRequired ? Result::ACTION_REQUIRED : Command::SUCCESS);
    }
}
