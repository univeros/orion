<?php

declare(strict_types=1);

namespace Orion\Cli;

use Orion\Orion;
use Orion\Wiring\Config;
use RuntimeException;

use function getenv;
use function is_file;
use function is_string;
use function sprintf;

/**
 * `--bootstrap=<file>` (or `ORION_BOOTSTRAP`): a PHP file returning the application's `Orion` or its `Config`, so the
 * commands run on the application's storage, engines and keys.
 */
final class Bootstrap
{
    public static function load(mixed $option): Orion
    {
        $file = is_string($option) && $option !== '' ? $option : getenv('ORION_BOOTSTRAP');
        if (!is_string($file) || $file === '') {
            throw new RuntimeException('Pass --bootstrap=<file> or set ORION_BOOTSTRAP: a PHP file returning your Orion\Orion or Orion\Wiring\Config.');
        }
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('The bootstrap file %s does not exist.', $file));
        }
        $result = require $file;
        if ($result instanceof Config) {
            $result = Orion::create($result);
        }

        return $result instanceof Orion ? $result : throw new RuntimeException(sprintf('%s must return an Orion\Orion or an Orion\Wiring\Config.', $file));
    }
}
