<?php

declare(strict_types=1);

namespace Orion\Psr15;

use RuntimeException;

/**
 * The caller lacks the permission the endpoint declares: answered as 403, nothing searched.
 */
final class Forbidden extends RuntimeException
{
}
