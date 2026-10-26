<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use Orion\Cli\Context;
use Orion\Cli\Result;
use Orion\IndexName;
use Orion\Outbox\Drain;
use Orion\Outbox\DrainResult;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function array_map;
use function is_string;

/**
 * `orion outbox:drain [<index>]`: writes the outbox to the engines, one index or every built one, and reports where
 * each stands; with `--dry-run`, only where each stands.
 */
final class DrainCommand extends OrionCommand
{
    protected function define(): void
    {
        $this->setName('outbox:drain')
            ->setDescription('Write outbox entries to their indices and report lag')
            ->addArgument('index', InputArgument::OPTIONAL, 'One index (alias); every built one when omitted')
            ->addOption('batch', null, InputOption::VALUE_REQUIRED, 'Entries per batch', (string) Drain::DEFAULT_BATCH)
            ->withDryRun();
    }

    protected function handle(InputInterface $input): Result
    {
        $graph = $this->context->graph($input);
        $drain = $graph->drain();
        $batch = Context::count($input->getOption('batch'), 'batch', 1);
        $indices = is_string($input->getArgument('index')) ? [Context::index($input)] : $graph->aliases()->aliases();
        $results = array_map(
            static fn(IndexName $index): DrainResult => $input->getOption('dry-run') === true ? $drain->status($index) : $drain->run($index, $batch),
            $indices,
        );

        return new Result(['indices' => array_map(static fn(DrainResult $result): array => $result->toArray(), $results)]);
    }
}
