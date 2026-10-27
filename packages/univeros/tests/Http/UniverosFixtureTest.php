<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Http;

use Orion\Testing\Endpoint\EndpointFixtureTestCase;

/**
 * The endpoint fixtures recorded through the bare PSR-15 pipeline, replayed through the Univeros module on Cycle
 * storage with a real Polaris Gate: the module changes no response.
 */
final class UniverosFixtureTest extends EndpointFixtureTestCase
{
    protected static function harnessClass(): string
    {
        return UniverosHarness::class;
    }
}
