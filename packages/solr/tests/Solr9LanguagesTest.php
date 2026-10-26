<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

use PHPUnit\Framework\Attributes\Group;

/**
 * Solr 9; set ORION_SOLR9_URL.
 */
#[Group('solr9')]
final class Solr9LanguagesTest extends SolrLanguages
{
    protected static function urlVariable(): string
    {
        return 'ORION_SOLR9_URL';
    }
}
