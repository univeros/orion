<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use Orion\Cli\Context;
use Orion\Cli\Result;
use Orion\Manifest\Declaration;
use Orion\Schema\IndexSchema;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function array_map;
use function is_array;

/**
 * `orion drift [--schema=<file>...]`: where each declared schema (the manifests' by default) stands against its index; `--check` exits 3 on any
 * drift, for CI.
 */
final class DriftCommand extends OrionCommand
{
    protected function define(): void
    {
        $this->setName('drift')
            ->setDescription('Report drift between declared schemas and their indices')
            ->addOption('schema', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'An index schema file; repeat for each index (the search: manifests when omitted)')
            ->withEngine()
            ->addOption('check', null, InputOption::VALUE_NONE, 'Exit 3 when any index drifted or is missing');
    }

    protected function handle(InputInterface $input): Result
    {
        $graph = $this->context->graph($input);
        $files = $input->getOption('schema');
        $schemas = is_array($files) && $files !== []
            ? array_map(static fn(mixed $file): IndexSchema => Context::schema($file), $files)
            : array_map(static fn(Declaration $declaration): IndexSchema => $declaration->schema, $graph->manifest()->declarations());
        $indices = [];
        $inSync = true;
        foreach ($schemas as $schema) {
            $engine = Context::engine($input, $graph, $schema->index);
            $state = Context::state($graph->planner($engine)->plan($schema), $graph->aliases()->current($schema->index) !== null);
            $inSync = $inSync && $state === 'in_sync';
            $indices[] = ['index' => $schema->index->value, 'engine' => $engine, 'state' => $state];
        }
        if ($indices === []) {
            throw new \InvalidArgumentException('Nothing to compare: declare indices in search: manifests, or pass --schema.');
        }

        return new Result(['in_sync' => $inSync, 'indices' => $indices], !$inSync && $input->getOption('check') === true ? Result::ACTION_REQUIRED : Command::SUCCESS);
    }
}
