<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use Orion\Cli\Context;
use Orion\Cli\Result;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

/**
 * `orion plan <index> --schema=<file>`: the classified diff from the declared schema to the live and registered index.
 */
final class PlanCommand extends OrionCommand
{
    protected function define(): void
    {
        $this->setName('plan')
            ->setDescription('Diff a declared schema against the live index and classify every change')
            ->addArgument('index', InputArgument::REQUIRED, 'The index (alias)')
            ->withSchema()
            ->withEngine();
    }

    protected function handle(InputInterface $input): Result
    {
        $graph = $this->context->graph($input);
        $index = Context::index($input);
        $schema = Context::schema($input->getOption('schema'), $index);
        $engine = Context::engine($input, $graph, $index);
        $built = $graph->aliases()->current($index) !== null;
        $plan = $graph->planner($engine)->plan($schema);

        return new Result([
            'index' => $index->value,
            'engine' => $engine,
            'state' => Context::state($plan, $built),
            'requires_reindex' => $built && $plan->requiresReindex(),
            'plan' => $plan->toArray(),
        ]);
    }
}
