<?php

declare(strict_types=1);

namespace Orion\Psr15\Tests;

use PHPUnit\Framework\Attributes\Group;

/**
 * The endpoint fixtures on Elasticsearch 8.19 with client 8.19 (ORION_ELASTICSEARCH8_URL).
 */
#[Group('elasticsearch8')]
final class Elasticsearch8FixtureTest extends ElasticsearchFixtureTest
{
    protected static function urlVariable(): string
    {
        return 'ORION_ELASTICSEARCH8_URL';
    }
}
