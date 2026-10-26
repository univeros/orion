<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Doctor;

use Altair\Container\Container;
use Altair\Doctor\CheckRegistry;
use Altair\Doctor\Configuration\DoctorConfiguration;
use Altair\Doctor\Contracts\CheckInterface;
use Altair\Doctor\Contracts\FixableCheckInterface;
use Altair\Doctor\Doctor;
use Altair\Doctor\Result\CheckResult;
use Altair\Doctor\Result\CheckStatus;
use DateTimeImmutable;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\IndexName;
use Orion\Receipt\SodiumSigner;
use Orion\Registry\EngineRegistry;
use Orion\Registry\IndexRegistration;
use Orion\Schema\IndexSchema;
use Orion\Storage\Schema;
use Orion\Testing\AliasingEngine;
use Orion\Wiring\Graph;
use Orion\Write\Document;
use PHPUnit\Framework\TestCase;
use Univeros\Orion\Doctor\Probe;
use Univeros\Orion\Tests\Support\TestHost;

use function array_map;
use function base64_encode;
use function file_put_contents;
use function mkdir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;

final class DoctorChecksTest extends TestCase
{
    private const string PRODUCTS = "index: products\nschema:\n  title: { type: text }\n";

    private Container $container;
    private Graph $graph;
    private string $manifests;

    protected function setUp(): void
    {
        $this->boot(self::PRODUCTS);
    }

    public function testTheSevenChecksJoinTheDoctorAfterTheDatabase(): void
    {
        $names = array_map(static fn(CheckInterface $check): string => $check->name(), $this->registry()->all());

        self::assertSame([
            'orion_engine_reachable', 'orion_registry_current', 'orion_schema_drift', 'orion_alias_resolves',
            'orion_outbox_lag', 'orion_dead_letters', 'orion_receipts_verify',
        ], $names);
        self::assertContains('database_reachable', $this->check('orion_registry_current')->dependsOn());
    }

    public function testTheChecksReachTheFrameworksDoctorWithoutAnythingResolvingTheRegistryFirst(): void
    {
        $container = TestHost::boot(TestHost::ENVIRONMENT, static function (Container $container): void {
            (new DoctorConfiguration(sys_get_temp_dir()))->apply($container);
        });
        $doctor = $container->get(Doctor::class);
        \assert($doctor instanceof Doctor);

        $report = $doctor->run(only: ['orion_engine_reachable']);

        self::assertSame(['orion_engine_reachable'], array_map(static fn(CheckResult $result): string => $result->name, $report->checks));
    }

    public function testCredentialsInAFailureAreRedactedBeforeTheDoctorPrintsThem(): void
    {
        $message = Probe::message(new \RuntimeException('cURL error 7: Failed to connect to https://elastic:s3cret@search.internal:9200/_cat'));

        self::assertSame('cURL error 7: Failed to connect to https://***@search.internal:9200/_cat', $message);
    }

    public function testTheEngineIsReachable(): void
    {
        self::assertSame(CheckStatus::Ok, $this->checked('orion_engine_reachable')->status);
    }

    public function testADeclaredIndexNotYetBuiltWarnsAndFixBuildsIt(): void
    {
        $result = $this->checked('orion_registry_current');

        self::assertSame(CheckStatus::Warn, $result->status);
        self::assertStringContainsString('products', $result->detail);
        self::assertTrue($this->fix('orion_registry_current'));
        self::assertSame('products_v1', $this->graph->aliases()->current(IndexName::of('products'))?->physical->value);
        self::assertSame(CheckStatus::Ok, $this->checked('orion_registry_current')->status);
    }

    public function testFixReleasesAClaimACrashLeftWithoutAnAliasAndBuildsTheIndex(): void
    {
        $this->graph->registry()->register(new IndexRegistration(IndexName::of('products_v1'), IndexName::of('products'), 'memory', 1, IndexSchema::for('products')->text('title'), new DateTimeImmutable('-1 hour')));

        self::assertStringContainsString('never aliased', $this->checked('orion_registry_current')->detail);
        self::assertTrue($this->fix('orion_registry_current'));
        self::assertSame('products_v1', $this->graph->aliases()->current(IndexName::of('products'))?->physical->value);
    }

    public function testFixLeavesARecentClaimAloneBecauseACreateMayStillBeRunning(): void
    {
        $engine = $this->graph->engines()->get('memory');
        $this->graph->registry()->register(new IndexRegistration(IndexName::of('products_v1'), IndexName::of('products'), 'memory', 1, IndexSchema::for('products')->text('title'), new DateTimeImmutable()));
        $engine->ensure(IndexSchema::for('products_v1')->text('title'));

        self::assertStringContainsString('in progress', $this->checked('orion_registry_current')->detail);
        self::assertFalse($this->fix('orion_registry_current'));
        self::assertNotNull($engine->inspect(IndexName::of('products_v1')));
        self::assertNotNull($this->graph->registry()->find(IndexName::of('products_v1')));
    }

    public function testFixLeavesAClaimOnAnotherEngineThanTheDeclarationAlone(): void
    {
        $this->boot("index: products\nengine: memory\nschema:\n  title: { type: text }\n", engines: new EngineRegistry(memory: new InMemoryEngine(), other: $other = new InMemoryEngine()));
        $this->graph->registry()->register(new IndexRegistration(IndexName::of('products_v1'), IndexName::of('products'), 'other', 1, IndexSchema::for('products')->text('title'), new DateTimeImmutable('-1 hour')));
        $other->ensure(IndexSchema::for('products_v1')->text('title'));

        self::assertFalse($this->fix('orion_registry_current'));
        self::assertNotNull($other->inspect(IndexName::of('products_v1')));
    }

    public function testFixDropsAnEmptyUnregisteredLeftoverButNeverOneHoldingDocuments(): void
    {
        $engine = $this->graph->engines()->get('memory');
        $engine->ensure(IndexSchema::for('products_v1')->text('title'));

        self::assertStringContainsString('products_v1', $this->checked('orion_registry_current')->detail);
        self::assertTrue($this->fix('orion_registry_current'));
        self::assertNotNull($this->graph->aliases()->current(IndexName::of('products')));

        $this->boot(self::PRODUCTS);
        $engine = $this->graph->engines()->get('memory');
        $engine->ensure(IndexSchema::for('products_v1')->text('title'));
        $engine->index(IndexName::of('products_v1'), new Document('1', ['title' => 'Someone\'s data'], 1));
        self::assertFalse($this->fix('orion_registry_current'));
        self::assertNull($this->graph->aliases()->current(IndexName::of('products')));
        self::assertNotNull($engine->inspect(IndexName::of('products_v1')));
    }

    public function testAdditiveDriftWarnsAndFixAppliesItWhileBreakingDriftErrorsWithTheReindexCommand(): void
    {
        $this->graph->planner('memory')->apply(IndexSchema::for('products')->text('title'));
        $this->boot("index: products\nschema:\n  title: { type: text }\n  sku: { type: keyword }\n", keepDatabase: true);

        self::assertSame(CheckStatus::Warn, $this->checked('orion_schema_drift')->status);
        self::assertTrue($this->fix('orion_schema_drift'));
        self::assertSame(CheckStatus::Ok, $this->checked('orion_schema_drift')->status);

        $this->boot("index: products\nschema:\n  title: { type: keyword }\n  sku: { type: keyword }\n", keepDatabase: true);
        $breaking = $this->checked('orion_schema_drift');
        self::assertSame(CheckStatus::Error, $breaking->status);
        self::assertSame(['type' => 'run_command', 'command' => 'bin/altair orion:reindex products'], $breaking->agentAction?->toArray());
        self::assertFalse($this->fix('orion_schema_drift'), 'Breaking drift is never fixed in place.');
    }

    public function testAnAliasTheEngineMovedWithoutTheRegistryIsAnError(): void
    {
        $engine = new AliasingEngine();
        $this->boot(self::PRODUCTS, engines: new EngineRegistry(native: $engine));
        $this->graph->planner('native')->apply(IndexSchema::for('products')->text('title'));
        self::assertSame(CheckStatus::Ok, $this->checked('orion_alias_resolves')->status);

        $engine->ensure(IndexSchema::for('products_v2')->text('title'));
        $engine->alias(IndexName::of('products'), IndexName::of('products_v2'));
        $result = $this->checked('orion_alias_resolves');

        self::assertSame(CheckStatus::Error, $result->status);
        self::assertStringContainsString('products_v2', $result->detail);
    }

    public function testARegisteredIndexMissingFromTheEngineIsAnError(): void
    {
        $this->graph->planner('memory')->apply(IndexSchema::for('products')->text('title'));
        $this->graph->engines()->get('memory')->drop(IndexName::of('products_v1'));

        self::assertSame(CheckStatus::Error, $this->checked('orion_alias_resolves')->status);
    }

    public function testLagAboveTheThresholdWarnsWithTheDrainCommand(): void
    {
        $this->boot(self::PRODUCTS, ['ORION_DOCTOR_MAX_LAG' => '1']);
        $this->graph->planner('memory')->apply(IndexSchema::for('products')->text('title'));
        $this->graph->outbox()->index(IndexName::of('products'), 'p1', ['title' => 'Lamp']);
        self::assertSame(CheckStatus::Ok, $this->checked('orion_outbox_lag')->status);

        $this->graph->outbox()->index(IndexName::of('products'), 'p2', ['title' => 'Desk']);
        $result = $this->checked('orion_outbox_lag');

        self::assertSame(CheckStatus::Warn, $result->status);
        self::assertSame('bin/altair orion:outbox:drain', $result->agentAction?->toArray()['command'] ?? null);
    }

    public function testAnUnreadableLagThresholdIsAnError(): void
    {
        $this->boot(self::PRODUCTS, ['ORION_DOCTOR_MAX_LAG' => 'lots']);

        self::assertSame(CheckStatus::Error, $this->checked('orion_outbox_lag')->status);
    }

    public function testReceiptsThatExistButCannotBeVerifiedSaySo(): void
    {
        $this->graph->storage()->insert(Schema::COUNTER, ['name' => 'receipt', 'value' => 1]);

        $result = $this->checked('orion_receipts_verify');

        self::assertSame(CheckStatus::Warn, $result->status);
        self::assertStringContainsString('cannot be verified', $result->detail);
    }

    public function testADeadLetterIsAnErrorWithTheRetryCommand(): void
    {
        $this->graph->planner('memory')->apply(IndexSchema::for('products')->text('title'));
        $this->graph->outbox()->index(IndexName::of('products'), 'p1', ['title' => 'Lamp', 'colour' => 'red']);
        $this->graph->drain()->run(IndexName::of('products'));

        $result = $this->checked('orion_dead_letters');

        self::assertSame(CheckStatus::Error, $result->status);
        self::assertSame('bin/altair orion:outbox:retry products', $result->agentAction?->toArray()['command'] ?? null);
    }

    public function testReceiptsWarnWhenOffAndErrorWhenTheChainBreaks(): void
    {
        self::assertSame(CheckStatus::Warn, $this->checked('orion_receipts_verify')->status);

        $this->boot(self::PRODUCTS, ['ORION_RECEIPT_PUBLIC_KEYS' => 'k1:' . base64_encode(SodiumSigner::fromSeed(str_repeat("\x01", 32), 'k1')->publicKey())]);
        self::assertSame(CheckStatus::Ok, $this->checked('orion_receipts_verify')->status);

        $this->graph->storage()->insert(Schema::COUNTER, ['name' => 'receipt', 'value' => 1]);
        self::assertSame(CheckStatus::Error, $this->checked('orion_receipts_verify')->status);
    }

    public function testTheDoctorRunsTheChecksAndFixesThroughItsRunner(): void
    {
        $report = (new Doctor($this->registry()))->run(only: ['orion_registry_current'], fix: true);

        self::assertSame(CheckStatus::Ok, $report->checks[0]->status);
    }

    /**
     * @param array<string, string> $env
     */
    private function boot(string $manifest, array $env = [], ?EngineRegistry $engines = null, bool $keepDatabase = false): void
    {
        $this->manifests = sys_get_temp_dir() . '/orion-doctor-' . uniqid();
        mkdir($this->manifests);
        file_put_contents($this->manifests . '/search.yaml', $manifest);
        $storage = $keepDatabase ? $this->graph->storage() : null;
        $memory = $keepDatabase ? $this->graph->engines() : new EngineRegistry(memory: new InMemoryEngine());
        $this->container = TestHost::boot([...TestHost::ENVIRONMENT, 'ORION_MANIFESTS' => $this->manifests, ...$env], static function (Container $container) use ($engines, $memory, $storage): void {
            $container->singleton(CheckRegistry::class, static fn(): CheckRegistry => new CheckRegistry());
            $container->singleton(EngineRegistry::class, static fn(): EngineRegistry => $engines ?? $memory);
            if ($storage !== null) {
                $container->singleton(\Orion\Storage\Storage::class, static fn(): \Orion\Storage\Storage => $storage);
            }
        });
        $graph = $this->container->get(Graph::class);
        \assert($graph instanceof Graph);
        $this->graph = $graph;
    }

    private function registry(): CheckRegistry
    {
        $registry = $this->container->get(CheckRegistry::class);
        \assert($registry instanceof CheckRegistry);

        return $registry;
    }

    private function check(string $name): CheckInterface
    {
        foreach ($this->registry()->all() as $check) {
            if ($check->name() === $name) {
                return $check;
            }
        }
        self::fail('No check ' . $name);
    }

    private function checked(string $name): CheckResult
    {
        return $this->check($name)->run();
    }

    private function fix(string $name): bool
    {
        $check = $this->check($name);
        \assert($check instanceof FixableCheckInterface);

        return $check->fix();
    }
}
