<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Testing\FaultInjectionTestCase;

/**
 * Runs the fault-injection harness against the SolrCloud node in {@see urlVariable()}; skipped when it is unset.
 */
abstract class SolrFaults extends FaultInjectionTestCase
{
    abstract protected static function urlVariable(): string;

    protected function createEngine(): SearchAdapter&SchemaAdapter&WriteAdapter
    {
        return SolrServer::engine(static::urlVariable(), $this);
    }
}
