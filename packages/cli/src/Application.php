<?php

declare(strict_types=1);

namespace Orion\Cli;

use Orion\Cli\Command\ApplyCommand;
use Orion\Cli\Command\CapabilitiesCommand;
use Orion\Cli\Command\DrainCommand;
use Orion\Cli\Command\DriftCommand;
use Orion\Cli\Command\FlushCommand;
use Orion\Cli\Command\KeygenCommand;
use Orion\Cli\Command\PlanCommand;
use Orion\Cli\Command\PruneCommand;
use Orion\Cli\Command\ReindexCommand;
use Orion\Cli\Command\RetryCommand;
use Orion\Cli\Command\StatusCommand;
use Orion\Cli\Command\VerifyCommand;
use Orion\Orion;
use Symfony\Component\Console\Application as Console;

/**
 * `bin/orion` (docs/spec/revision.md §1, v1 §14). A host passes its `Orion`; without one each command loads it from
 * `--bootstrap` or `ORION_BOOTSTRAP`. Every command prints YAML, or canonical JSON with `--json`: the same state and
 * arguments print the same bytes. Exit codes: 0 done, 1 failed (the error is printed as data), 3 action required
 * (a breaking change needs a reindex, drift under `--check`).
 */
final class Application extends Console
{
    public const string VERSION = '0.1.0';

    public function __construct(?Orion $orion = null)
    {
        parent::__construct('orion', self::VERSION);
        $context = new Context($orion);
        $this->addCommands([
            new PlanCommand($context),
            new ApplyCommand($context),
            new ReindexCommand($context),
            new PruneCommand($context),
            new DriftCommand($context),
            new StatusCommand($context),
            new FlushCommand($context),
            new VerifyCommand($context),
            new CapabilitiesCommand($context),
            new DrainCommand($context),
            new RetryCommand($context),
            new KeygenCommand($context),
        ]);
    }
}
