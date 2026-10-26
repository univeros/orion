<?php

declare(strict_types=1);

namespace Orion\Cli\Tests;

use Orion\Schema\IndexSchema;
use PHPUnit\Framework\TestCase;

use function escapeshellarg;
use function exec;
use function file_put_contents;
use function implode;
use function json_decode;
use function json_encode;
use function sys_get_temp_dir;
use function uniqid;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

/**
 * `bin/orion` itself, in fresh processes: the same command on the same state prints the same bytes.
 */
final class BinTest extends TestCase
{
    private const string BIN = __DIR__ . '/../bin/orion';
    private const string BOOTSTRAP = __DIR__ . '/Fixtures/bootstrap.php';

    public function testTheSameCommandInTwoProcessesPrintsTheSameJson(): void
    {
        $schema = sys_get_temp_dir() . '/orion-bin-' . uniqid() . '.json';
        file_put_contents($schema, json_encode(IndexSchema::for('products')->text('title', language: 'en')->toArray(), JSON_THROW_ON_ERROR));
        $command = ['apply', 'products', '--schema=' . $schema, '--json', '--bootstrap=' . self::BOOTSTRAP];

        [$firstCode, $first] = self::orion($command);
        [$secondCode, $second] = self::orion($command);

        self::assertSame([0, 0], [$firstCode, $secondCode]);
        self::assertSame($first, $second);
        self::assertSame('created', json_decode($first, true, 512, JSON_THROW_ON_ERROR)['operation']);
    }

    public function testWithoutABootstrapTheCommandFailsAsJson(): void
    {
        [$code, $output] = self::orion(['status', 'products', '--json'], ['ORION_BOOTSTRAP' => '']);

        self::assertSame(1, $code);
        self::assertStringContainsString('bootstrap', json_decode($output, true, 512, JSON_THROW_ON_ERROR)['error']['message']);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     * @return array{int, string}
     */
    private static function orion(array $arguments, array $environment = []): array
    {
        $prefix = '';
        foreach ($environment as $name => $value) {
            $prefix .= $name . '=' . escapeshellarg($value) . ' ';
        }
        $lines = [];
        $code = 0;
        exec($prefix . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::BIN) . ' ' . implode(' ', array_map(escapeshellarg(...), $arguments)) . ' 2>/dev/null', $lines, $code);

        return [$code, implode("\n", $lines)];
    }
}
