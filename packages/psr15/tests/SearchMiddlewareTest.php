<?php

declare(strict_types=1);

namespace Orion\Psr15\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\ServerRequest;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\IndexName;
use Orion\Orion;
use Orion\Psr15\SearchMiddleware;
use Orion\Registry\EngineRegistry;
use Orion\Tenancy\Tenancy;
use Orion\Testing\Endpoint\EndpointFixtureTestCase;
use Orion\Testing\InMemoryStorage;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

use function json_decode;
use function sys_get_temp_dir;
use function uniqid;
use function mkdir;
use function file_put_contents;

/**
 * The middleware serves the declared routes under the host's prefix and leaves every other request alone; the
 * handler answers configuration that does not fit as 500 and logs the cause.
 */
final class SearchMiddlewareTest extends TestCase
{
    public function testDeclaredRoutesAreServedUnderThePrefixAndOthersPassThrough(): void
    {
        $graph = self::graph(EndpointFixtureTestCase::manifestDirectory());
        $factory = new HttpFactory();
        $pipeline = PipelineHarness::pipeline([new SearchMiddleware($graph->manifest(), $graph->searcher(), $factory, $factory, prefix: 'api/')]);

        self::assertSame(404, $pipeline->handle(new ServerRequest('GET', '/products/search'))->getStatusCode());
        self::assertSame(405, $pipeline->handle(new ServerRequest('GET', '/api/items/search'))->getStatusCode());
        self::assertSame('POST', $pipeline->handle(new ServerRequest('GET', '/api/items/search'))->getHeaderLine('Allow'));
        self::assertSame(500, $pipeline->handle(new ServerRequest('GET', '/api/products/search'))->getStatusCode(), 'products declares a permission and no authorizer is configured');
    }

    public function testAnEndpointWithAPermissionButNoAuthorizerIsAServerErrorWithTheCauseLogged(): void
    {
        $graph = self::graph(EndpointFixtureTestCase::manifestDirectory());
        $factory = new HttpFactory();
        $logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $logged = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->logged[] = $level . ': ' . (string) ($context['reason'] ?? '');
            }
        };
        $pipeline = PipelineHarness::pipeline([new SearchMiddleware($graph->manifest(), $graph->searcher(), $factory, $factory, logger: $logger)]);

        $response = $pipeline->handle(new ServerRequest('GET', '/products/search'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/problem+json', $response->getHeaderLine('Content-Type'));
        $body = (array) json_decode((string) $response->getBody(), true);
        self::assertSame('https://orion-search.dev/problems/search-failed', $body['type']);
        self::assertStringNotContainsString('Authorizer', (string) $body['detail']);
        self::assertSame(['error: The endpoint /products/search declares the permission products.search, but no Authorizer is configured to check it.'], $logger->logged);
    }

    public function testAnIndexNotBuiltIsAClientErrorNamingTheFix(): void
    {
        $graph = self::graph(EndpointFixtureTestCase::manifestDirectory());
        $factory = new HttpFactory();
        $pipeline = PipelineHarness::pipeline([new SearchMiddleware($graph->manifest(), $graph->searcher(), $factory, $factory)]);

        $response = $pipeline->handle(new ServerRequest('POST', '/items/search'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('"items" is not built: apply its schema first.', ((array) json_decode((string) $response->getBody(), true))['detail']);
    }

    public function testEmptyMapsAreObjectsInTheBody(): void
    {
        $root = sys_get_temp_dir() . '/orion-endpoint-' . uniqid();
        mkdir($root);
        file_put_contents($root . '/search.yaml', "index: notes\nschema:\n  title: { type: text }\nsearch: { endpoint: GET /notes, query_fields: [title] }\n");
        $graph = self::graph($root);
        $notes = $graph->manifest()->find('notes');
        self::assertNotNull($notes);
        $graph->planner('memory')->apply($notes->schema);
        $graph->outbox()->index(IndexName::of('notes'), 'n1', []);
        $graph->drain()->run(IndexName::of('notes'));
        $factory = new HttpFactory();
        $pipeline = PipelineHarness::pipeline([new SearchMiddleware($graph->manifest(), $graph->searcher(), $factory, $factory)]);

        $body = (string) $pipeline->handle(new ServerRequest('GET', '/notes'))->getBody();

        self::assertStringContainsString('"source":{},"highlights":{}', $body);
        self::assertStringContainsString('"facets":{}', $body);
    }

    private static function graph(string $manifests): Graph
    {
        return Orion::create(new Config(
            storage: new InMemoryStorage(),
            engines: new EngineRegistry(memory: new InMemoryEngine()),
            tenancy: Tenancy::single(),
            manifestDirectories: [$manifests],
        ))->graph();
    }
}
