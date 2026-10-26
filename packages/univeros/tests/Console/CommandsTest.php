<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Univeros\Orion\Console\Commands;
use Univeros\Orion\Tests\Support\TestHost;

use function array_map;
use function file_put_contents;
use function json_decode;
use function sort;
use function sys_get_temp_dir;
use function uniqid;

use const JSON_THROW_ON_ERROR;

final class CommandsTest extends TestCase
{
    public function testTheOrionCommandsJoinTheApplicationsConsoleAsOrionStar(): void
    {
        $names = array_map(static fn(Command $command): string => (string) $command->getName(), Commands::all(TestHost::boot([], database: false)));
        sort($names);

        self::assertSame([
            'orion:apply', 'orion:capabilities', 'orion:drift', 'orion:flush', 'orion:outbox:drain', 'orion:outbox:retry',
            'orion:plan', 'orion:prune', 'orion:receipts:keygen', 'orion:reindex', 'orion:status', 'orion:verify',
        ], $names);
    }

    public function testTheyRunOnTheModulesOrion(): void
    {
        $commands = [];
        foreach (Commands::all(TestHost::boot()) as $command) {
            $commands[(string) $command->getName()] = $command;
        }
        $schema = sys_get_temp_dir() . '/orion-module-' . uniqid() . '.yaml';
        file_put_contents($schema, "index: products\nfields:\n  title: { type: text }\n");

        $apply = new CommandTester($commands['orion:apply']);
        $apply->execute(['index' => 'products', '--schema' => $schema, '--json' => true]);
        $status = new CommandTester($commands['orion:status']);
        $status->execute(['index' => 'products', '--json' => true]);

        self::assertSame(0, $apply->getStatusCode(), $apply->getDisplay());
        self::assertSame('products_v1', json_decode($status->getDisplay(), true, 512, JSON_THROW_ON_ERROR)['current']['physical']);
    }
}
