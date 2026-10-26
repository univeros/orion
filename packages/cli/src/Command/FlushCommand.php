<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use InvalidArgumentException;
use Orion\Cli\Context;
use Orion\Cli\Result;
use Orion\Support\Assert;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

use function sprintf;

/**
 * `orion flush <index>`: refresh or commit the index behind the alias (an honest no-op where the engine has neither).
 */
final class FlushCommand extends OrionCommand
{
    protected function define(): void
    {
        $this->setName('flush')
            ->setDescription('Refresh or commit the index behind the alias')
            ->addArgument('index', InputArgument::REQUIRED, 'The index (alias)');
    }

    protected function handle(InputInterface $input): Result
    {
        $graph = $this->context->graph($input);
        $index = Context::index($input);
        $current = $graph->aliases()->current($index) ?? throw new InvalidArgumentException(sprintf('%s has not been built.', Assert::show($index->value)));
        $target = $graph->aliases()->targetOf($current);
        $graph->engines()->get($current->engine)->flush($target, await: true);

        return new Result(['index' => $index->value, 'flushed' => $target->value]);
    }
}
