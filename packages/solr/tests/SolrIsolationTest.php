<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

/**
 * Solr 10; set ORION_SOLR_URL, e.g. `http://127.0.0.1:8983/solr`.
 */
final class SolrIsolationTest extends SolrIsolation
{
    protected static function urlVariable(): string
    {
        return 'ORION_SOLR_URL';
    }
}
