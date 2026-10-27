<?php

declare(strict_types=1);

namespace Orion\Psr15\Tests;

/**
 * The endpoint fixtures on Meilisearch 1.13 (ORION_MEILISEARCH113_URL).
 */
final class Meilisearch113FixtureTest extends MeilisearchFixtureTest
{
    protected static function urlVariable(): string
    {
        return 'ORION_MEILISEARCH113_URL';
    }
}
