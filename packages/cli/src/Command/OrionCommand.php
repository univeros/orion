<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use Orion\Cli\Context;
use Orion\Cli\Output;
use Orion\Cli\Result;
use ReflectionClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * The shape every `bin/orion` command shares: `--json` and `--bootstrap`, data printed by {@see Output}, and a failure
 * printed as data (`error.type`, `error.message`) with exit code 1, so an agent branches on the output either way.
 */
abstract class OrionCommand extends Command
{
    public function __construct(protected readonly Context $context)
    {
        parent::__construct();
    }

    abstract protected function define(): void;

    abstract protected function handle(InputInterface $input): Result;

    final protected function configure(): void
    {
        $this->define();
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print canonical JSON instead of YAML')
            ->addOption('bootstrap', 'b', InputOption::VALUE_REQUIRED, 'A PHP file returning the application\'s Orion or Config (or ORION_BOOTSTRAP)');
    }

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->handle($input);
        } catch (Throwable $failure) {
            $result = new Result(['error' => ['type' => (new ReflectionClass($failure))->getShortName(), 'message' => $failure->getMessage()]], Command::FAILURE);
        }
        $output->write(Output::render($result->data, (bool) $input->getOption('json')));

        return $result->code;
    }

    protected function withSchema(): static
    {
        return $this->addOption('schema', 's', InputOption::VALUE_REQUIRED, 'The index schema as a JSON or YAML file (IndexSchema::toArray() form)');
    }

    protected function withEngine(): static
    {
        return $this->addOption('engine', 'e', InputOption::VALUE_REQUIRED, 'The engine; defaults to the one that built the index, or the only one configured');
    }

    protected function withDryRun(): static
    {
        return $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would happen and change nothing');
    }
}
