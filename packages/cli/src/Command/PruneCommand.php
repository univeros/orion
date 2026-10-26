<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use Orion\Cli\Context;
use Orion\Cli\Result;
use Orion\Registry\IndexRegistration;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function array_map;

/**
 * `orion prune <index> --grace=<seconds>`: drops the versions the alias no longer points at once the grace window
 * since the last swap has passed, with a receipt each.
 */
final class PruneCommand extends OrionCommand
{
    public const string DEFAULT_GRACE = '300';

    protected function define(): void
    {
        $this->setName('prune')
            ->setDescription('Drop replaced versions of the index after the grace window')
            ->addArgument('index', InputArgument::REQUIRED, 'The index (alias)')
            ->addOption('grace', null, InputOption::VALUE_REQUIRED, 'Seconds since the last swap before a replaced version may go', self::DEFAULT_GRACE)
            ->withDryRun();
    }

    protected function handle(InputInterface $input): Result
    {
        $graph = $this->context->graph($input);
        $index = Context::index($input);
        $grace = Context::count($input->getOption('grace'), 'grace', 0);
        $reindexer = $graph->reindexer(Context::engine($input, $graph, $index));
        $dryRun = $input->getOption('dry-run') === true;
        $versions = $dryRun ? $reindexer->prunable($index, $grace) : $reindexer->prune($index, $grace);
        $data = ['index' => $index->value, 'grace' => $grace, 'dropped' => array_map(static fn(IndexRegistration $r): string => $r->physical->value, $versions)];

        return new Result($dryRun ? [...$data, 'dry_run' => true] : $data);
    }
}
