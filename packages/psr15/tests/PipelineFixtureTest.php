<?php

declare(strict_types=1);

namespace Orion\Psr15\Tests;

use Orion\Testing\Endpoint\EndpointFixtureTestCase;

/**
 * The endpoint fixtures through the bare PSR-15 pipeline on the in-memory engine: where they are recorded.
 */
final class PipelineFixtureTest extends EndpointFixtureTestCase
{
    protected static function harnessClass(): string
    {
        return PipelineHarness::class;
    }
}
