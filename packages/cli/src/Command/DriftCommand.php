<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use Orion\Cli\Context;
use Orion\Cli\Result;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function is_array;

/**
 * `orion drift --schema=<file>...`: where each declared schema stands against its index; `--check` exits 3 on any
 * drift, for CI.
 */
final class DriftCommand extends OrionCommand
{
    protected function define(): void
    {
        $this->setName('drift')
            ->setDescription('Report drift between declared schemas and their indices')
            ->addOption('schema', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'An index schema file; repeat for each index')
            ->withEngine()
            ->addOption('check', null, InputOption::VALUE_NONE, 'Exit 3 when any index drifted or is missing');
    }

    protected function handle(InputInterface $input): Result
    {
        $graph = $this->context->graph($input);
        $files = $input->getOption('schema');
        $indices = [];
        $inSync = true;
        foreach (is_array($files) ? $files : [] as $file) {
            $schema = Context::schema($file);
            $engine = Context::engine($input, $graph, $schema->index);
            $state = Context::state($graph->planner($engine)->plan($schema), $graph->aliases()->current($schema->index) !== null);
            $inSync = $inSync && $state === 'in_sync';
            $indices[] = ['index' => $schema->index->value, 'engine' => $engine, 'state' => $state];
        }
        if ($indices === []) {
            throw new \InvalidArgumentException('Pass at least one --schema.');
        }

        return new Result(['in_sync' => $inSync, 'indices' => $indices], !$inSync && $input->getOption('check') === true ? Result::ACTION_REQUIRED : Command::SUCCESS);
    }
}
