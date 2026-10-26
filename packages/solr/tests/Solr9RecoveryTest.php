<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

use PHPUnit\Framework\Attributes\Group;

/**
 * Set ORION_SOLR9_URL.
 */
#[Group('solr9')]
final class Solr9RecoveryTest extends SolrRecovery
{
    protected static function urlVariable(): string
    {
        return 'ORION_SOLR9_URL';
    }
}
