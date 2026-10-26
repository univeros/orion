<?php

declare(strict_types=1);

namespace Orion\Solr\Tests;

use Orion\IndexName;
use Orion\Schema\IndexSchema;
use Orion\Solr\Configset;
use Orion\Solr\SolrEngine;
use Orion\Write\Document;
use Orion\Write\WriteStatus;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function curl_exec;
use function curl_init;
use function curl_setopt;
use function dirname;
use function file_get_contents;
use function getenv;
use function http_build_query;
use function random_bytes;

use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;

/**
 * What a crash, an interrupted upload or a concurrent build leaves behind is repaired by the next `ensure()`, never
 * mistaken for a finished index.
 */
abstract class SolrRecovery extends TestCase
{
    private SolrEngine $engine;
    private IndexName $index;
    private string $url;

    abstract protected static function urlVariable(): string;

    protected function setUp(): void
    {
        $this->engine = SolrServer::engine(static::urlVariable(), $this);
        $this->url = (string) getenv(static::urlVariable());
        $this->index = IndexName::of('orion_recovery_' . bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        if (isset($this->engine)) {
            $this->engine->drop($this->index);
        }
    }

    public function testAHalfUploadedConfigsetIsCompletedBeforeItIsUsed(): void
    {
        $this->call('admin/configs', ['action' => 'DELETE', 'name' => Configset::name()]);
        $this->call('admin/configs', ['action' => 'UPLOAD', 'name' => Configset::name(), 'filePath' => 'solrconfig.xml', 'overwrite' => 'true'], (string) file_get_contents(dirname(__DIR__) . '/configset/solrconfig.xml'));
        self::assertStringContainsString(Configset::name(), $this->call('admin/configs', ['action' => 'LIST']), 'The interrupted upload left the configset listed.');

        $this->engine->ensure($this->schema());

        self::assertTrue($this->engine->plan($this->schema())->isEmpty());
    }

    public function testAHalfBuiltCollectionIsCompletedNotMistakenForBuilt(): void
    {
        $this->engine->ensure(IndexSchema::for($this->index->value)->text('title', 'en'));

        $this->engine->ensure($this->schema());

        self::assertTrue($this->engine->plan($this->schema())->isEmpty());
    }

    public function testAConfigsetCopyACrashLeftBehindIsReplaced(): void
    {
        // Some other schema under the copy's name: _default's dynamic fields would accept anything.
        $this->call('admin/configs', ['action' => 'CREATE', 'name' => 'orion.' . $this->index->value, 'baseConfigSet' => '_default']);

        $this->engine->ensure($this->schema());

        self::assertTrue($this->engine->plan($this->schema())->isEmpty());
        $result = $this->engine->index($this->index, new Document('x', ['title' => 'X', 'dynamic_s' => 'y'], 1))->await(5.0);
        self::assertSame(WriteStatus::Failed, $result->outcomes[0]->status, 'The copy is Orion\'s, without dynamic fields.');
    }

    public function testADocumentUsingAReservedFieldFailsAlone(): void
    {
        $this->engine->ensure($this->schema());

        $result = $this->engine->index($this->index, new Document('ok', ['title' => 'Fine'], 1), new Document('bad', ['title' => 'Bad', 'orion_version' => 999], 1))->await(5.0);

        self::assertSame([WriteStatus::Applied, WriteStatus::Failed], [$result->outcomes[0]->status, $result->outcomes[1]->status]);
    }

    public function testTheResponseFormatIsOrions(): void
    {
        $this->engine->ensure($this->schema());

        self::assertSame([], $this->engine->raw($this->index, static fn(): array => ['q' => '*:*', 'wt' => 'xml'])->hits);
    }

    private function schema(): IndexSchema
    {
        return IndexSchema::for($this->index->value)->text('title', 'en')->keyword('sku', filterable: true);
    }

    /**
     * @param array<string, string> $params
     */
    private function call(string $handler, array $params, ?string $body = null): string
    {
        $curl = curl_init($this->url . '/' . $handler . '?' . http_build_query($params + ['wt' => 'json']));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/octet-stream']);
        }

        return (string) curl_exec($curl);
    }
}
