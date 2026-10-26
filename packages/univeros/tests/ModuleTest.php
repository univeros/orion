<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests;

use Altair\Container\Container;
use Altair\Messaging\Contracts\MessageBusInterface;
use Altair\Messaging\Discovery\HandlerRegistry;
use Altair\Module\Contracts\ManifestContributorInterface;
use Altair\Module\Contracts\MigrationDirectoriesProviderInterface;
use Altair\Module\Contracts\ModuleInterface;
use Altair\Observability\Metrics\Meter;
use Altair\Observability\Recorder\InMemoryRecorder;
use Cycle\Database\DatabaseManager;
use DateTimeImmutable;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Elasticsearch\ElasticsearchEngine;
use Orion\IndexName;
use Orion\Orion;
use Orion\Outbox\Dispatcher;
use Orion\Outbox\Outbox;
use Orion\Pdo\SqlStorage;
use Orion\Receipt\SodiumSigner;
use Orion\Registry\EngineRegistry;
use Orion\Schema\IndexSchema;
use Orion\Storage\Schema;
use Orion\Tenancy\Tenancy;
use Orion\Testing\FixedClock;
use Orion\Wiring\Graph;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Throwable;
use Univeros\Orion\Messaging\BusDispatcher;
use Univeros\Orion\Messaging\DrainIndex;
use Univeros\Orion\Messaging\DrainIndexHandler;
use Univeros\Orion\Module;
use Univeros\Orion\Observability\MeterMetrics;
use Univeros\Orion\Tests\Support\TestHost;

use function array_keys;
use function base64_encode;
use function implode;
use function file_put_contents;
use function getcwd;
use function chdir;
use function mkdir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;

final class ModuleTest extends TestCase
{
    public function testIsAUniverosModuleWithItsMigrationAndAManifestSection(): void
    {
        $module = new Module();

        self::assertInstanceOf(ModuleInterface::class, $module);
        self::assertInstanceOf(MigrationDirectoriesProviderInterface::class, $module);
        self::assertInstanceOf(ManifestContributorInterface::class, $module);
        self::assertSame('univeros/orion', $module->name());
    }

    public function testApplyingTheModuleBuildsNothingSoDbMigrateNeedsNoConfiguration(): void
    {
        $container = TestHost::boot([], database: false);

        self::assertTrue($container->has(Orion::class));
    }

    public function testOneOrionRunsOnTheHostsCycleConnection(): void
    {
        $container = TestHost::boot();
        $graph = $container->get(Graph::class);
        \assert($graph instanceof Graph);
        $database = $container->get(DatabaseManager::class);
        \assert($database instanceof DatabaseManager);

        try {
            $database->database()->transaction(static function () use ($graph): void {
                $graph->outbox()->index(IndexName::of('products'), 'p1', ['title' => 'Lamp']);

                throw new RuntimeException('the entity write failed');
            });
        } catch (RuntimeException) {
        }

        self::assertSame($container->get(Orion::class), $container->get(Orion::class));
        self::assertInstanceOf(SqlStorage::class, $graph->storage());
        self::assertSame(0, $graph->storage()->count(Schema::OUTBOX, []), 'The outbox entry rolled back with the host\'s transaction.');
        self::assertSame($graph->outbox(), $container->get(Outbox::class));
        self::assertInstanceOf(InMemoryEngine::class, $graph->engines()->get('memory'));
    }

    public function testTenancyIsAnExplicitChoice(): void
    {
        $container = TestHost::boot(['ORION_ENGINE' => 'memory']);

        $this->expectExceptionMessage('ORION_TENANCY');

        $container->get(Orion::class);
    }

    public function testTheEngineIsNamedByOrionEngine(): void
    {
        $orion = TestHost::boot(['ORION_ENGINE' => 'elasticsearch', 'ORION_ELASTICSEARCH_URL' => 'http://127.0.0.1:1, http://127.0.0.1:2', 'ORION_TENANCY' => 'single'])->get(Orion::class);
        \assert($orion instanceof Orion);

        self::assertSame(['elasticsearch'], $orion->graph()->engines()->names());
        self::assertInstanceOf(ElasticsearchEngine::class, $orion->graph()->engines()->get('elasticsearch'));
    }

    public function testAnEngineTheModuleCannotBuildIsRefusedWithTheVariableNamed(): void
    {
        foreach (
            [
            'ORION_ENGINE' => ['ORION_TENANCY' => 'single'],
            'ORION_ELASTICSEARCH_URL' => ['ORION_ENGINE' => 'elasticsearch', 'ORION_TENANCY' => 'single'],
            'solr' => ['ORION_ENGINE' => 'solr', 'ORION_TENANCY' => 'single'],
            ] as $named => $env
        ) {
            try {
                TestHost::boot($env)->get(Orion::class);
                self::fail('An engine the module cannot build was accepted.');
            } catch (Throwable $e) {
                self::assertStringContainsString($named, $e->getMessage());
            }
        }
    }

    public function testWhatTheApplicationBindsWins(): void
    {
        $engines = new EngineRegistry(search: new InMemoryEngine());
        $clock = new FixedClock(new DateTimeImmutable('2026-10-27 09:00:00+00:00'));
        $graph = TestHost::boot([], static function (Container $container) use ($engines, $clock): void {
            $container->singleton(EngineRegistry::class, static fn(): EngineRegistry => $engines);
            $container->singleton(Tenancy::class, static fn(): Tenancy => Tenancy::single());
            $container->singleton(ClockInterface::class, static fn(): ClockInterface => $clock);
        })->get(Graph::class);
        \assert($graph instanceof Graph);

        self::assertSame($engines, $graph->engines());
        self::assertSame($clock, $graph->clock());
    }

    public function testReceiptKeysComeFromTheEnvironment(): void
    {
        $signer = SodiumSigner::fromSeed(str_repeat("\x01", 32), 'prod-1');
        $pair = SodiumSigner::generateKeyPair();
        $graph = TestHost::boot([
            ...TestHost::ENVIRONMENT,
            'ORION_RECEIPT_KID' => 'prod-2',
            'ORION_RECEIPT_SECRET_KEY' => $pair['secret_key'],
            'ORION_RECEIPT_PREVIOUS_KEYS' => 'prod-1:' . base64_encode($signer->publicKey()),
        ])->get(Graph::class);
        \assert($graph instanceof Graph);

        self::assertSame('prod-2', $graph->config()->receiptSigner?->kid());
        self::assertTrue($graph->config()->receiptSigner->verify('m', $signer->sign('m'), 'prod-1'));
        self::assertNotNull($graph->planner('memory')->apply(IndexSchema::for('products')->text('title'))->receipt);
    }

    public function testAnAuditorVerifiesWithPublicKeysAlone(): void
    {
        $signer = SodiumSigner::fromSeed(str_repeat("\x01", 32), 'prod-1');
        $graph = TestHost::boot([...TestHost::ENVIRONMENT, 'ORION_RECEIPT_PUBLIC_KEYS' => 'prod-1:' . base64_encode($signer->publicKey())])->get(Graph::class);
        \assert($graph instanceof Graph);

        self::assertNull($graph->config()->receiptSigner);
        self::assertTrue($graph->config()->receiptVerifier?->verify('m', $signer->sign('m'), 'prod-1'));
        self::assertTrue($graph->verifier()->verify()->ok());
    }

    public function testMalformedKeyMaterialIsRefusedWithTheVariableNamed(): void
    {
        $container = TestHost::boot([...TestHost::ENVIRONMENT, 'ORION_RECEIPT_KID' => 'prod-1', 'ORION_RECEIPT_SECRET_KEY' => 'not base64!']);

        $this->expectExceptionMessage('ORION_RECEIPT_SECRET_KEY');

        $container->get(Orion::class);
    }

    public function testPartialOrAmbiguousReceiptSettingsFailClosed(): void
    {
        $key = base64_encode(SodiumSigner::fromSeed(str_repeat("\x01", 32), 'k1')->publicKey());
        $mistakes = [
            ['ORION_RECEIPT_KID' => 'k1'],
            ['ORION_RECEIPT_PREVIOUS_KEYS' => 'k0:' . $key],
            ['ORION_RECEIPT_PUBLIC_KEYS' => 'k1:' . $key . ',k1:' . $key],
        ];
        foreach ($mistakes as $settings) {
            try {
                TestHost::boot([...TestHost::ENVIRONMENT, ...$settings])->get(Orion::class);
                self::fail('Receipt settings that cannot be what was meant were accepted: ' . implode(', ', array_keys($settings)));
            } catch (Throwable $e) {
                self::assertStringContainsString('ORION_RECEIPT', $e->getMessage());
            }
        }
    }

    public function testTheBusCannotMakeTheDrainReportIndicesThatAreNotBuilt(): void
    {
        $recorder = new InMemoryRecorder();
        $container = TestHost::boot(TestHost::ENVIRONMENT, static function (Container $container) use ($recorder): void {
            $container->singleton(Meter::class, static fn(): Meter => new Meter($recorder));
        });
        $handler = $container->get(DrainIndexHandler::class);
        \assert($handler instanceof DrainIndexHandler);

        $handler(new DrainIndex('anything_at_all'));

        self::assertSame([], $recorder->metrics());
    }

    public function testCountersAndTheLagGoToTheFrameworkMeter(): void
    {
        $recorder = new InMemoryRecorder();
        $graph = TestHost::boot(TestHost::ENVIRONMENT, static function (Container $container) use ($recorder): void {
            $container->singleton(Meter::class, static fn(): Meter => new Meter($recorder));
        })->get(Graph::class);
        \assert($graph instanceof Graph);

        $graph->drain()->run(IndexName::of('products'));

        self::assertInstanceOf(MeterMetrics::class, $graph->metrics());
        self::assertNotSame([], $recorder->metrics());
    }

    public function testTheDefaultManifestDirectoryIsTheProjectsNotTheWorkingDirectorys(): void
    {
        $elsewhere = sys_get_temp_dir() . '/orion-cwd-' . uniqid();
        mkdir($elsewhere . '/api', 0o777, true);
        file_put_contents($elsewhere . '/api/search.yaml', "index: stray\nschema:\n  title: { type: text }\n");
        $cwd = (string) getcwd();
        chdir($elsewhere);
        try {
            $graph = TestHost::boot()->get(Graph::class);
            \assert($graph instanceof Graph);

            self::assertNull($graph->manifest()->find('stray'), 'A search.yaml in whatever directory the process runs from is not the project\'s.');
        } finally {
            chdir($cwd);
        }
    }

    public function testAManifestDirectoryTheApplicationNamesMustExistForTheAgentManifestToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Module())->manifest(TestHost::boot(['ORION_MANIFESTS' => '/no/such/directory'], database: false));
    }

    public function testTheHandlerIsRegisteredWhateverOrderTheMessagingConfigurationAppliesIn(): void
    {
        $container = TestHost::boot();
        $container->singleton(HandlerRegistry::class, static fn(): HandlerRegistry => new HandlerRegistry());

        $registry = $container->get(HandlerRegistry::class);
        \assert($registry instanceof HandlerRegistry);

        self::assertSame(DrainIndexHandler::class, $registry->handlersFor(DrainIndex::class)[0]->handlerClass ?? null);
    }

    public function testWithTheBusTheDrainRunsOnTheWorker(): void
    {
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message);
            }
        };
        $registry = new HandlerRegistry();
        $container = TestHost::boot(TestHost::ENVIRONMENT, static function (Container $container) use ($bus, $registry): void {
            $container->singleton(MessageBusInterface::class, static fn(): MessageBusInterface => $bus);
            $container->singleton(HandlerRegistry::class, static fn(): HandlerRegistry => $registry);
        });
        $graph = $container->get(Graph::class);
        \assert($graph instanceof Graph);
        $graph->planner('memory')->apply(IndexSchema::for('products')->text('title'));
        $graph->outbox()->index(IndexName::of('products'), 'p1', ['title' => 'Lamp']);

        $dispatcher = $container->get(Dispatcher::class);
        \assert($dispatcher instanceof Dispatcher);
        $dispatcher->dispatch(IndexName::of('products'));

        self::assertInstanceOf(BusDispatcher::class, $dispatcher);
        self::assertEquals([new DrainIndex('products')], $bus->sent);
        $registry = $container->get(HandlerRegistry::class);
        \assert($registry instanceof HandlerRegistry);
        self::assertSame(DrainIndexHandler::class, $registry->handlersFor(DrainIndex::class)[0]->handlerClass);
        $handler = $container->get(DrainIndexHandler::class);
        \assert($handler instanceof DrainIndexHandler);
        $handler(new DrainIndex('products'));
        self::assertSame(0, $graph->drain()->status(IndexName::of('products'))->lag);
    }
}
