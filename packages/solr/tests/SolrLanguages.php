<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

use Orion\IndexName;
use Orion\Schema\IndexSchema;
use Orion\Solr\Schema;
use Orion\Solr\SolrEngine;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function random_bytes;
use function str_replace;

/**
 * Every language a text field may declare is analysis Solr accepts, folded or not, and reads back without drift.
 */
abstract class SolrLanguages extends TestCase
{
    private SolrEngine $engine;
    private IndexName $index;

    abstract protected static function urlVariable(): string;

    protected function setUp(): void
    {
        $this->engine = SolrServer::engine(static::urlVariable(), $this);
        $this->index = IndexName::of('orion_languages_' . bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        if (isset($this->engine)) {
            $this->engine->drop($this->index);
        }
    }

    public function testEveryLanguageIsAnalysisSolrAccepts(): void
    {
        $schema = IndexSchema::for($this->index->value);
        foreach (Schema::languages() as $language) {
            $name = str_replace('-', '_', $language);
            $schema = $schema->text('t_' . $name, $language)->text('f_' . $name, $language, folding: true);
        }

        $this->engine->ensure($schema);

        self::assertTrue($this->engine->plan($schema)->isEmpty());
    }
}
