<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

use Orion\Solr\UpdateResponse;
use Orion\Write\WriteOutcome;
use Orion\Write\WriteStatus;
use PHPUnit\Framework\TestCase;

use function array_map;
use function str_repeat;

final class UpdateResponseTest extends TestCase
{
    public function testEachDocumentIsAccountedForByItsId(): void
    {
        $outcomes = UpdateResponse::outcomes(['responseHeader' => ['status' => 0, 'errors' => [
            ['type' => 'ADD', 'id' => 'b', 'message' => "ERROR: [doc=b] unknown field 'x'"],
            ['type' => 'ADD', 'id' => 'c', 'message' => 'user version is not high enough: [7]'],
        ]]], ['a', 'b', 'c']);

        self::assertSame([['a', WriteStatus::Applied, null], ['b', WriteStatus::Failed, "ERROR: [doc=b] unknown field 'x'"], ['c', WriteStatus::Stale, null]], self::summary($outcomes));
    }

    public function testADeleteRefusedForItsVersionIsStale(): void
    {
        $outcomes = UpdateResponse::outcomes(['responseHeader' => ['status' => 0, 'errors' => [['type' => 'DELID', 'id' => 'a', 'message' => 'user version is not high enough: [8]']]]], ['a']);

        self::assertSame([['a', WriteStatus::Stale, null]], self::summary($outcomes));
    }

    public function testAFailedRequestFailsEveryDocument(): void
    {
        $outcomes = UpdateResponse::outcomes(['responseHeader' => ['status' => 500], 'error' => ['msg' => 'Solr is unhappy']], ['a', 'b']);

        self::assertSame([['a', WriteStatus::Failed, 'Solr is unhappy'], ['b', WriteStatus::Failed, 'Solr is unhappy']], self::summary($outcomes));
    }

    public function testAResponseWithoutAHeaderTrustsNothing(): void
    {
        self::assertSame(WriteStatus::Failed, UpdateResponse::outcomes([], ['a'])[0]->status);
    }

    public function testReasonsAreBounded(): void
    {
        $outcome = UpdateResponse::outcomes(['responseHeader' => ['status' => 0, 'errors' => [['id' => 'a', 'message' => str_repeat('x', 1000)]]]], ['a'])[0];

        self::assertLessThanOrEqual(UpdateResponse::MAX_REASON + 3, strlen((string) $outcome->error));
    }

    /**
     * @param list<WriteOutcome> $outcomes
     * @return list<array{string, WriteStatus, string|null}>
     */
    private static function summary(array $outcomes): array
    {
        return array_map(static fn(WriteOutcome $o): array => [$o->id, $o->status, $o->error], $outcomes);
    }
}
