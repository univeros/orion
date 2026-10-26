<?php

declare(strict_types=1);

namespace Orion\Cli\Tests;

use Orion\IndexName;
use Orion\Schema\IndexSchema;
use Symfony\Component\Yaml\Yaml;

use function array_diff_key;
use function json_decode;

final class LifecycleCommandsTest extends CliTestCase
{
    public function testPlanPrintsTheClassifiedPlan(): void
    {
        [$code, $out] = $this->orion('plan', ['index' => 'products', '--schema' => $this->schemaFile(self::products())]);

        self::assertSame(0, $code);
        self::assertSame(['index' => 'products', 'engine' => 'memory', 'state' => 'missing', 'requires_reindex' => false], [
            'index' => $out['index'], 'engine' => $out['engine'], 'state' => $out['state'], 'requires_reindex' => $out['requires_reindex'],
        ]);
        self::assertSame(['sku', 'title'], self::paths($out['plan']['changes']));
    }

    public function testWithoutASchemaFileTheManifestDeclaresTheIndex(): void
    {
        $this->app = $this->freshOrion([$this->manifest("index: products\nengine: memory\nschema:\n  title: { type: text, language: en }\n")]);

        [$code, $out] = $this->orion('apply', ['index' => 'products']);
        [$drift, $report] = $this->orion('drift', ['--check' => true]);
        [$missing, $error] = $this->orion('plan', ['index' => 'orders']);

        self::assertSame([0, 'created'], [$code, $out['operation']]);
        self::assertSame([0, true], [$drift, $report['in_sync']]);
        self::assertSame(1, $missing);
        self::assertStringContainsString('manifest', $error['error']['message']);
    }

    public function testASchemaFileMayBeAManifest(): void
    {
        $manifest = $this->manifest("index: products\nschema:\n  title: { type: text, language: en }\n") . '/search.yaml';

        [$code, $out] = $this->orion('plan', ['index' => 'products', '--schema' => $manifest]);

        self::assertSame([0, ['title']], [$code, self::paths($out['plan']['changes'])]);
    }

    public function testApplyCreatesTheIndexAndPrintsItsReceipt(): void
    {
        $schema = $this->schemaFile(self::products(), 'yaml');

        [$created, $first] = $this->orion('apply', ['index' => 'products', '--schema' => $schema]);
        [$unchanged, $second] = $this->orion('apply', ['index' => 'products', '--schema' => $schema]);

        self::assertSame([0, 'created', 'products_v1', 1], [$created, $first['operation'], $first['physical'], $first['receipt']['sequence']]);
        self::assertSame([0, 'unchanged', null], [$unchanged, $second['operation'], $second['receipt']]);
    }

    public function testApplyingABreakingChangeChangesNothingAndAsksForAReindex(): void
    {
        $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::products())]);

        [$code, $out] = $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::rebuilt())]);

        self::assertSame(3, $code);
        self::assertSame('reindex_required', $out['operation']);
    }

    public function testADryRunApplyChangesNothing(): void
    {
        [$code, $out] = $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::products()), '--dry-run' => true]);

        self::assertSame([0, true, 'create'], [$code, $out['dry_run'], $out['would']]);
        self::assertSame([], $this->app->graph()->registry()->versions(IndexName::of('products')));
    }

    public function testReindexRebuildsByReplayAndPrintsTheResult(): void
    {
        $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::products())]);
        $this->seed(3);
        $this->app->graph()->drain()->run(IndexName::of('products'));

        [$code, $out] = $this->orion('reindex', ['index' => 'products', '--schema' => $this->schemaFile(self::rebuilt()), '--batch' => '2']);

        self::assertSame(0, $code);
        self::assertSame(['products', 1, 2, 'products_v2', 3, 3, 'reindexed'], [$out['index'], $out['from_version'], $out['to_version'], $out['physical'], $out['documents'], $out['to_offset'], $out['receipt']['operation']]);
    }

    public function testADryRunReindexReportsWhatItWouldDo(): void
    {
        $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::products())]);
        $this->seed(3);

        [$code, $out] = $this->orion('reindex', ['index' => 'products', '--schema' => $this->schemaFile(self::rebuilt()), '--dry-run' => true]);

        self::assertSame(0, $code);
        self::assertSame([true, 'products_v2', 3, null, true], [$out['dry_run'], $out['next'], $out['entries'], $out['pending'], $out['requires_reindex']]);
        self::assertCount(1, $this->app->graph()->registry()->versions(IndexName::of('products')));
    }

    public function testAbortWithNothingPendingReportsNothing(): void
    {
        $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::products())]);

        [$code, $out] = $this->orion('reindex', ['index' => 'products', '--abort' => true]);

        self::assertSame([0, null], [$code, $out['aborted']]);
    }

    public function testPruneDropsReplacedVersionsAfterTheGraceWindow(): void
    {
        $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::products())]);
        $this->orion('reindex', ['index' => 'products', '--schema' => $this->schemaFile(self::rebuilt())]);

        [, $dry] = $this->orion('prune', ['index' => 'products', '--grace' => '0', '--dry-run' => true]);
        [$code, $out] = $this->orion('prune', ['index' => 'products', '--grace' => '0']);

        self::assertSame(['products_v1'], $dry['dropped']);
        self::assertSame([0, ['products_v1']], [$code, $out['dropped']]);
        self::assertNull($this->engine->inspect(IndexName::of('products_v1')));
    }

    public function testDriftReportsEachIndexAndFailsTheCheckWhenAnyDrifted(): void
    {
        $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::products())]);
        $orders = IndexSchema::for('orders')->keyword('status');

        [$clean] = $this->orion('drift', ['--schema' => [$this->schemaFile(self::products())], '--check' => true]);
        [$code, $out] = $this->orion('drift', ['--schema' => [$this->schemaFile(self::products()->keyword('brand')), $this->schemaFile($orders)], '--check' => true]);

        self::assertSame(0, $clean);
        self::assertSame(3, $code);
        self::assertData([['index' => 'products', 'engine' => 'memory', 'state' => 'additive'], ['index' => 'orders', 'engine' => 'memory', 'state' => 'missing']], $out['indices']);
        self::assertFalse($out['in_sync']);
    }

    public function testStatusDescribesTheIndex(): void
    {
        $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::products())]);
        $this->seed(2);

        [$code, $out] = $this->orion('status', ['index' => 'products']);

        self::assertSame(0, $code);
        self::assertData([
            'index' => 'products',
            'engine' => 'memory',
            'current' => ['physical' => 'products_v1', 'version' => 1, 'schema_hash' => self::products()->hash()],
            'versions' => [['physical' => 'products_v1', 'version' => 1, 'schema_hash' => self::products()->hash(), 'created_at' => '2026-10-26T12:00:00+00:00']],
            'swapped_at' => '2026-10-26T12:00:00+00:00',
            'outbox' => ['head' => 2, 'drained_to' => 0, 'lag' => 2, 'dead_letters' => 0],
            'reindex' => null,
            'receipts' => 1,
        ], array_diff_key($out, ['capabilities' => true]));
        self::assertTrue($out['capabilities']['Facets']);
        self::assertFalse($out['capabilities']['NativeAliases']);
    }

    public function testFlushRefreshesTheIndexBehindTheAlias(): void
    {
        $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::products())]);

        [$code, $out] = $this->orion('flush', ['index' => 'products']);

        self::assertSame(0, $code);
        self::assertData(['index' => 'products', 'flushed' => 'products_v1'], $out);
    }

    public function testCapabilitiesListsEveryEngine(): void
    {
        [$code, $out] = $this->orion('capabilities');

        self::assertSame(0, $code);
        self::assertTrue($out['memory']['Vectors']);
        self::assertFalse($out['memory']['HybridFusion']);
    }

    public function testAFailureIsReportedAsJsonWithItsType(): void
    {
        [$code, $out] = $this->orion('plan', ['index' => 'orders', '--schema' => $this->schemaFile(self::products())]);

        self::assertSame(1, $code);
        self::assertSame('InvalidArgumentException', $out['error']['type']);
        self::assertStringContainsString('products', $out['error']['message']);
    }

    public function testTheSameCommandsOnTheSameStatePrintTheSameBytes(): void
    {
        $first = $this->session();
        $this->app = $this->freshOrion();

        self::assertSame($first, $this->session());
    }

    public function testWithoutJsonTheSameDataPrintsAsYaml(): void
    {
        $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::products())]);

        $human = $this->human('status', ['index' => 'products']);
        [, $json] = $this->orion('status', ['index' => 'products']);

        self::assertEquals($json, Yaml::parse($human));
    }

    /**
     * @return list<string>
     */
    private function session(): array
    {
        $products = $this->schemaFile(self::products());
        $rebuilt = $this->schemaFile(self::rebuilt());
        $outputs = [];
        $outputs[] = $this->orion('apply', ['index' => 'products', '--schema' => $products])[2];
        $this->seed(3);
        $outputs[] = $this->orion('outbox:drain', ['index' => 'products'])[2];
        $outputs[] = $this->orion('reindex', ['index' => 'products', '--schema' => $rebuilt, '--batch' => '2'])[2];
        $outputs[] = $this->orion('prune', ['index' => 'products', '--grace' => '0'])[2];
        $outputs[] = $this->orion('status', ['index' => 'products'])[2];
        $outputs[] = $this->orion('verify')[2];
        self::assertNotNull(json_decode($outputs[5], true));

        return $outputs;
    }
}
