<?php

declare(strict_types=1);

namespace Orion\Cli;

use DateTimeImmutable;
use DateTimeZone;
use Orion\Support\Canonical;
use Symfony\Component\Yaml\Yaml;

use function json_encode;

use const DATE_ATOM;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * How a command's data is printed: canonical JSON (keys sorted at every depth) for agents, YAML for people.
 */
final class Output
{
    /**
     * @param array<array-key, mixed> $data
     */
    public static function render(array $data, bool $json): string
    {
        if ($json) {
            return json_encode(Canonical::sort($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR) . "\n";
        }

        return Yaml::dump($data, 10, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    public static function time(?DateTimeImmutable $time): ?string
    {
        return $time?->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }
}
