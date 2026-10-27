<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Mcp;

use Altair\Container\Container;
use Altair\Mcp\Exception\GuardrailException;
use Altair\Mcp\Guard\ServerMode;
use Altair\Mcp\Tool\ToolDescriptor;
use Altair\Mcp\Tool\ToolRegistry;
use Orion\IndexName;
use Orion\Tools\Tool;
use Orion\Tools\Tools;
use Orion\Wiring\Graph;
use PHPUnit\Framework\TestCase;
use Univeros\Orion\AgentSpec\ManifestSection;
use Univeros\Orion\Bootstrap\OrionConfig;
use Univeros\Orion\Mcp\ApplyTool;
use Univeros\Orion\Mcp\CompileQueryTool;
use Univeros\Orion\Mcp\McpTools;
use Univeros\Orion\Mcp\OrionTool;
use Univeros\Orion\Mcp\ReindexTool;
use Univeros\Orion\Module;
use Univeros\Orion\Tests\Support\TestHost;
use Orion\Manifest\Loader;

use function array_map;
use function count;
use function file_put_contents;
use function get_parent_class;
use function mkdir;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The core tools adapted to the framework's MCP server (revision §5): same names (prefixed `orion__`), descriptions
 * and schemas as core, registered on the host's registry, resolved through the host's container, and the mutating
 * ones gated by the server mode on top of core's `confirm`.
 */
final class McpToolsTest extends TestCase
{
    public function testEveryCoreToolIsAnMcpToolWithItsNameDescriptionAndSchema(): void
    {
        $graph = TestHost::boot()->get(Graph::class);
        self::assertInstanceOf(Graph::class, $graph);
        $core = [];
        foreach (Tools::for($graph) as $tool) {
            $core[$tool->name()] = $tool;
        }

        $descriptors = McpTools::descriptors();

        self::assertSame(array_map(static fn(string $name): string => OrionTool::PREFIX . $name, Tools::NAMES), array_map(static fn(ToolDescriptor $d): string => $d->name, $descriptors));
        foreach ($descriptors as $descriptor) {
            $tool = $core[substr($descriptor->name, strlen(OrionTool::PREFIX))] ?? null;
            self::assertInstanceOf(Tool::class, $tool, $descriptor->name);
            self::assertSame($tool->description(), $descriptor->description, $descriptor->name);
            self::assertSame($tool->inputSchema(), $descriptor->inputSchema, $descriptor->name . ': the schema file beside the adapter is the core schema.');
            self::assertSame(OrionTool::class, get_parent_class($descriptor->className), $descriptor->name);
        }
    }

    public function testTheToolsJoinTheHostsRegistryHoweverLateItIsBound(): void
    {
        $container = TestHost::boot();
        // The framework's serve command binds the registry after the modules applied.
        $container->factory(ToolRegistry::class, static fn(): ToolRegistry => new ToolRegistry())->shared();

        $registry = $container->get(ToolRegistry::class);

        self::assertInstanceOf(ToolRegistry::class, $registry);
        self::assertSame(count(Tools::NAMES), $registry->count());
        self::assertTrue($registry->has('orion__compile_query'));
        self::assertSame('object', $registry->get('orion__compile_query')->inputSchema['type'] ?? null);
        $listed = array_map(static fn(array $entry): string => (string) $entry['name'], $registry->listing());
        self::assertContains('orion__search', $listed);
    }

    public function testCompileQueryAnswersThroughTheHostsContainerWithoutExecuting(): void
    {
        $container = $this->host();
        $this->build($container);

        $tool = $container->make(CompileQueryTool::class);
        self::assertInstanceOf(CompileQueryTool::class, $tool);
        $compiled = $tool->call(['index' => 'products', 'query' => [['type' => 'match', 'field' => 'title', 'value' => 'running']]]);

        self::assertSame('memory', $compiled['engine']);
        self::assertSame('products_v1', $compiled['index']);
        self::assertSame([['type' => 'match', 'field' => 'title', 'value' => 'running', 'boost' => 2.0]], $compiled['native']['query']);
        self::assertNull($compiled['legs']);
    }

    public function testAMutationNeedsConfirmAndTheServersAllowWrites(): void
    {
        $container = $this->host();
        $graph = $container->get(Graph::class);
        self::assertInstanceOf(Graph::class, $graph);
        $gated = $container->make(ApplyTool::class);
        self::assertInstanceOf(ApplyTool::class, $gated);

        self::assertSame('create', $gated->call(['index' => 'products'])['would'], 'A preview needs no permission.');
        try {
            $gated->call(['index' => 'products', 'confirm' => true]);
            self::fail('apply ran without --allow-writes.');
        } catch (GuardrailException $refused) {
            self::assertStringContainsString('--allow-writes', $refused->getMessage());
        }
        self::assertSame('create', $gated->call(['index' => 'products'])['would'], 'Nothing was applied.');

        $readonly = new ApplyTool($graph, new ServerMode(readonly: true, allowWrites: true));
        try {
            $readonly->call(['index' => 'products', 'confirm' => true]);
            self::fail('apply ran on a read-only server.');
        } catch (GuardrailException) {
            self::assertSame('create', $gated->call(['index' => 'products'])['would'], 'readonly wins over allow-writes.');
        }

        $allowed = new ApplyTool($graph, new ServerMode(allowWrites: true));

        self::assertSame('created', $allowed->call(['index' => 'products', 'confirm' => true])['operation']);
        self::assertSame('nothing', $gated->call(['index' => 'products'])['would']);

        $reindex = $container->make(ReindexTool::class);
        self::assertInstanceOf(ReindexTool::class, $reindex);
        self::assertSame('products_v2', $reindex->call(['index' => 'products'])['next'], 'A reindex preview needs no permission.');
        try {
            $reindex->call(['index' => 'products', 'confirm' => true]);
            self::fail('reindex ran without --allow-writes.');
        } catch (GuardrailException) {
            self::assertSame(1, $graph->aliases()->current(IndexName::of('products'))?->version, 'Nothing was rebuilt.');
        }
        self::assertSame(2, (new ReindexTool($graph, new ServerMode(allowWrites: true)))->call(['index' => 'products', 'confirm' => true])['to_version']);
    }

    public function testTheAgentSpecListsTheTools(): void
    {
        $rendered = ManifestSection::render((new Loader())->load());

        self::assertStringContainsString('## MCP tools', $rendered);
        self::assertStringContainsString('`orion__compile_query`', $rendered);
        self::assertStringContainsString('--allow-writes', $rendered);
    }

    /**
     * A host whose manifests declare `products` on a single-tenant index with a boosted title.
     */
    private function host(): Container
    {
        $manifests = sys_get_temp_dir() . '/orion-mcp-' . uniqid();
        mkdir($manifests);
        file_put_contents($manifests . '/products.search.yaml', "index: products\nschema:\n  title: { type: text, boost: 2.0 }\n  sku: { type: keyword, filterable: true }\n");

        return TestHost::boot([...TestHost::ENVIRONMENT, OrionConfig::MANIFESTS => $manifests]);
    }

    private function build(Container $container): void
    {
        $graph = $container->get(Graph::class);
        self::assertInstanceOf(Graph::class, $graph);
        $declared = $graph->manifest()->find('products');
        self::assertNotNull($declared);
        $graph->planner('memory')->apply($declared->schema);
    }
}
