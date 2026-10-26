<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use Orion\Cli\Context;
use Orion\Cli\Result;
use Orion\Outbox\DeadLetter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

use function array_map;

/**
 * `orion outbox:retry <index>`: sends the index's dead letters again; with `--dry-run`, lists them.
 */
final class RetryCommand extends OrionCommand
{
    protected function define(): void
    {
        $this->setName('outbox:retry')
            ->setDescription('Resend the dead letters of an index')
            ->addArgument('index', InputArgument::REQUIRED, 'The index (alias)')
            ->withDryRun();
    }

    protected function handle(InputInterface $input): Result
    {
        $graph = $this->context->graph($input);
        $index = Context::index($input);
        if ($input->getOption('dry-run') === true) {
            return new Result(['index' => $index->value, 'dry_run' => true, 'dead_letters' => array_map(static fn(DeadLetter $letter): array => $letter->toArray(), $graph->deadLetters()->of($index))]);
        }

        return new Result($graph->deadLetters()->retry($index)->toArray());
    }
}
