<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Testing\Equivalence\QueryEquivalenceTestCase;

/**
 * Runs the query equivalence case against the SolrCloud node in {@see urlVariable()}; skipped when it is unset.
 */
abstract class SolrEquivalence extends QueryEquivalenceTestCase
{
    abstract protected static function urlVariable(): string;

    protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return SolrServer::engine(static::urlVariable(), $this);
    }
}
