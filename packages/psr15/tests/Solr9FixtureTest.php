<?php

declare(strict_types=1);

namespace Orion\Psr15\Tests;

use PHPUnit\Framework\Attributes\Group;

/**
 * The endpoint fixtures on SolrCloud 9 (ORION_SOLR9_URL).
 */
#[Group('solr9')]
final class Solr9FixtureTest extends SolrFixtureTest
{
    protected static function urlVariable(): string
    {
        return 'ORION_SOLR9_URL';
    }
}
