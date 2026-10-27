<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\AgentSpec;

use PHPUnit\Framework\TestCase;
use Univeros\Orion\Module;
use Univeros\Orion\Tests\Support\TestHost;

use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

final class ManifestSectionTest extends TestCase
{
    public function testTheSectionListsTheDeclaredIndicesAndTheCommandsWithoutTouchingTheDatabase(): void
    {
        $directory = sys_get_temp_dir() . '/orion-agent-' . uniqid();
        mkdir($directory);
        file_put_contents($directory . '/search.yaml', "index: products\nengine: elasticsearch\nschema:\n  title: { type: text, language: en }\n  sku: { type: keyword, filterable: true }\norg_key: org_id\nsearch: { endpoint: GET /products/search, permission: products.search, query_fields: [title] }\n");

        $section = (new Module())->manifest(TestHost::boot(['ORION_MANIFESTS' => $directory], database: false));

        self::assertStringContainsString('`products`', $section);
        self::assertStringContainsString('elasticsearch', $section);
        self::assertStringContainsString('`title` text', $section);
        self::assertStringContainsString('org key `org_id`', $section);
        self::assertStringContainsString('served at `GET /products/search` (permission `products.search`)', $section);
        self::assertStringContainsString('bin/altair orion:reindex <index>', $section);
        self::assertStringContainsString('orion_schema_drift', $section);
        self::assertSame($section, (new Module())->manifest(TestHost::boot(['ORION_MANIFESTS' => $directory], database: false)), 'The section is byte-stable.');
    }
}
