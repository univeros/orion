<?php

declare(strict_types=1);

namespace Orion\Cli\Tests;

use Orion\IndexName;
use Orion\Storage\Schema;

use function base64_decode;
use function strlen;

final class OutboxCommandsTest extends CliTestCase
{
    public function testDrainWritesTheOutboxAndReportsWhereEachIndexStands(): void
    {
        $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::products())]);
        $this->seed(2);

        [, $dry] = $this->orion('outbox:drain', ['--dry-run' => true]);
        [$code, $out] = $this->orion('outbox:drain');

        self::assertData([['index' => 'products', 'from' => 0, 'to' => 0, 'head' => 2, 'lag' => 2, 'applied' => 0, 'stale' => 0, 'failed' => 0]], $dry['indices']);
        self::assertSame(0, $code);
        self::assertData([['index' => 'products', 'from' => 0, 'to' => 2, 'head' => 2, 'lag' => 0, 'applied' => 2, 'stale' => 0, 'failed' => 0]], $out['indices']);
    }

    public function testRetryResendsTheDeadLetters(): void
    {
        $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::products())]);
        $this->app->graph()->outbox()->index(IndexName::of('products'), 'p1', ['title' => 'Lamp', 'colour' => 'red']);
        $this->orion('outbox:drain', ['index' => 'products']);

        [, $dry] = $this->orion('outbox:retry', ['index' => 'products', '--dry-run' => true]);
        [$code, $out] = $this->orion('outbox:retry', ['index' => 'products']);

        self::assertSame('p1', $dry['dead_letters'][0]['document']);
        self::assertSame(0, $code);
        self::assertData(['index' => 'products', 'applied' => 0, 'stale' => 0, 'failed' => 1, 'superseded' => 0], $out);
    }

    public function testVerifyChecksTheReceiptChainAndFailsWhenItIsBroken(): void
    {
        $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::products())]);
        $this->orion('apply', ['index' => 'products', '--schema' => $this->schemaFile(self::products()->keyword('brand'))]);

        [$ok, $good] = $this->orion('verify');
        $this->storage->update(Schema::RECEIPT, ['sequence' => 1], ['document_count' => 9]);
        [$broken, $bad] = $this->orion('verify');

        self::assertSame(0, $ok);
        self::assertData(['receipts' => 2, 'ok' => true, 'breaks' => [], 'head' => ['sequence' => 2, 'hash' => $good['head']['hash']]], $good);
        [$anchored, $cut] = $this->orion('verify', ['--head' => '3:' . $good['head']['hash']]);
        self::assertSame(1, $anchored);
        self::assertContains('the chain does not reach the anchored receipt 3', $cut['breaks']);
        self::assertSame([1, false], [$broken, $bad['ok']]);
    }

    public function testKeygenPrintsAnEd25519KeyPair(): void
    {
        [$code, $out] = $this->orion('receipts:keygen', ['--kid' => 'prod-2026']);

        self::assertSame(0, $code);
        self::assertSame('prod-2026', $out['kid']);
        self::assertSame(64, strlen((string) base64_decode($out['secret_key'], true)));
        self::assertSame(32, strlen((string) base64_decode($out['public_key'], true)));
    }
}
