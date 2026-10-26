<?php

declare(strict_types=1);

namespace Univeros\Orion\Console;

use Altair\Container\Container;
use Orion\Cli\Application;
use Orion\Cli\Context;
use Orion\Orion;
use Symfony\Component\Console\Command\Command;
use UnexpectedValueException;

/**
 * The `orion-search/cli` commands as `orion:*` on the application's Orion. `univeros/cli` discovers `#[Command]`
 * classes by directory and has no module hook, so the application's `bin/altair` adds these to its console:
 * `foreach (Commands::all($container) as $command) { $application->add($command); }`. Orion is built when a command
 * first needs it, so the console boots, and `db:migrate` runs, before Orion is configured.
 */
final class Commands
{
    public const string PREFIX = 'orion:';

    /**
     * @return list<Command>
     */
    public static function all(Container $container): array
    {
        $context = new Context(static function () use ($container): Orion {
            $orion = $container->get(Orion::class);

            return $orion instanceof Orion ? $orion : throw new UnexpectedValueException('The container binds Orion\Orion to something else.');
        });
        $commands = Application::commands($context);
        foreach ($commands as $command) {
            $command->setName(self::PREFIX . $command->getName());
        }

        return $commands;
    }
}
