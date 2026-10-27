<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

/**
 * Solr 10; set ORION_SOLR_URL.
 */
final class SolrQueryEquivalenceTest extends SolrEquivalence
{
    protected static function urlVariable(): string
    {
        return 'ORION_SOLR_URL';
    }
}
