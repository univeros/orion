<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

use PHPUnit\Framework\Attributes\Group;

/**
 * Solr 9; set ORION_SOLR9_URL. CI runs this group in its own job.
 */
#[Group('solr9')]
final class Solr9IsolationTest extends SolrIsolation
{
    protected static function urlVariable(): string
    {
        return 'ORION_SOLR9_URL';
    }
}
