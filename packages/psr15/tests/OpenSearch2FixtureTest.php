<?php

declare(strict_types=1);

namespace Orion\Psr15\Tests;

/**
 * The endpoint fixtures on OpenSearch 2.19 (ORION_OPENSEARCH2_URL).
 */
final class OpenSearch2FixtureTest extends OpenSearchFixtureTest
{
    protected static function urlVariable(): string
    {
        return 'ORION_OPENSEARCH2_URL';
    }
}
