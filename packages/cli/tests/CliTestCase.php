<?php

declare(strict_types=1);

namespace Orion\Cli\Tests;

use DateTimeImmutable;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Cli\Application;
use Orion\IndexName;
use Orion\Orion;
use Orion\Receipt\SodiumSigner;
use Orion\Registry\EngineRegistry;
use Orion\Schema\IndexSchema;
use Orion\Support\Canonical;
use Orion\Tenancy\Tenancy;
use Orion\Testing\InMemoryStorage;
use Orion\Testing\SettableClock;
use Orion\Wiring\Config;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

use function array_map;
use function file_put_contents;
use function is_array;
use function json_decode;
use function json_encode;
use function mkdir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;

use const JSON_THROW_ON_ERROR;

/**
 * Runs `bin/orion` commands in process against an in-memory Orion with a fixed clock and key.
 */
abstract class CliTestCase extends TestCase
{
    protected InMemoryStorage $storage;
    protected InMemoryEngine $engine;
    protected SettableClock $clock;
    protected Orion $app;
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/orion-cli-' . uniqid();
        mkdir($this->directory);
        $this->app = $this->freshOrion();
    }

    /**
     * @param list<string>|null $manifests
     */
    protected function freshOrion(?array $manifests = null): Orion
    {
        return Orion::create(new Config(
            storage: $this->storage = new InMemoryStorage(),
            engines: new EngineRegistry(memory: $this->engine = new InMemoryEngine()),
            tenancy: Tenancy::single(),
            clock: $this->clock = new SettableClock(new DateTimeImmutable('2026-10-26 12:00:00+00:00')),
            receiptSigner: SodiumSigner::fromSeed(str_repeat("\x04", 32), 'cli'),
            manifestDirectories: $manifests,
        ));
    }

    /**
     * A directory holding one `search.yaml`.
     */
    protected function manifest(string $yaml): string
    {
        $directory = $this->directory . '/api-' . uniqid();
        mkdir($directory);
        file_put_contents($directory . '/search.yaml', $yaml);

        return $directory;
    }

    /**
     * @param array<string, string|bool|list<string>> $arguments
     * @return array{int, array<string, mixed>, string} the exit code, the decoded JSON and the raw output
     */
    protected function orion(string $command, array $arguments = []): array
    {
        $tester = new ApplicationTester($this->application());
        $input = ['command' => $command, '--json' => true];
        foreach ($arguments as $name => $value) {
            $input[$name] = $value;
        }
        $code = $tester->run($input);
        $output = $tester->getDisplay();
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return [$code, is_array($decoded) ? $decoded : [], $output];
    }

    /**
     * @param array<string, string|bool> $arguments
     */
    protected function human(string $command, array $arguments = []): string
    {
        $tester = new ApplicationTester($this->application());
        $tester->run(['command' => $command, ...$arguments]);

        return $tester->getDisplay();
    }

    protected function application(): Application
    {
        $application = new Application($this->app);
        $application->setAutoExit(false);

        return $application;
    }

    protected function schemaFile(IndexSchema $schema, string $extension = 'json'): string
    {
        $file = $this->directory . '/' . $schema->index->value . '-' . uniqid() . '.' . $extension;
        file_put_contents($file, $extension === 'json' ? json_encode($schema->toArray(), JSON_THROW_ON_ERROR) : \Symfony\Component\Yaml\Yaml::dump($schema->toArray(), 6));

        return $file;
    }

    protected static function products(): IndexSchema
    {
        return IndexSchema::for('products')->text('title', language: 'en')->keyword('sku', filterable: true);
    }

    protected static function rebuilt(): IndexSchema
    {
        return IndexSchema::for('products')->text('title', language: 'en')->keyword('sku', filterable: true, sortable: true);
    }

    protected function seed(int $documents): void
    {
        $outbox = $this->app->graph()->outbox();
        for ($i = 1; $i <= $documents; ++$i) {
            $outbox->index(IndexName::of('products'), 'p' . $i, ['title' => 'Lamp ' . $i, 'sku' => 'S' . $i]);
        }
    }

    /**
     * The JSON output sorts keys at every depth; compare against the expected data sorted the same way.
     *
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    protected static function assertData(array $expected, array $actual): void
    {
        self::assertSame(Canonical::sort($expected), $actual);
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @return list<string>
     */
    protected static function paths(array $changes): array
    {
        return array_map(static fn(array $change): string => (string) $change['path'], $changes);
    }
}
