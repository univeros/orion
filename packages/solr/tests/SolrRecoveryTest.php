<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

/**
 * Set ORION_SOLR_URL.
 */
final class SolrRecoveryTest extends SolrRecovery
{
    protected static function urlVariable(): string
    {
        return 'ORION_SOLR_URL';
    }
}
