<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Http;

use Altair\Container\Container;
use Altair\Module\Contracts\MiddlewareProviderInterface;
use Closure;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Registry\EngineRegistry;
use Orion\Testing\Endpoint\EndpointFixtureTestCase;
use Orion\Testing\Endpoint\Harness;
use Orion\Wiring\Graph;
use Override;
use Polaris\Authorization\Gate;
use Polaris\Token\Token;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Univeros\Orion\Http\RequestToken;
use Univeros\Orion\Module;
use Univeros\Orion\Tests\Support\PolarisFixture;
use Univeros\Orion\Tests\Support\TestHost;

use function array_values;
use function count;
use function http_build_query;
use function json_decode;
use function json_encode;
use function parse_str;
use function usort;

use const JSON_THROW_ON_ERROR;

/**
 * The endpoint fixtures through the Univeros module (docs/spec/revision.md §5): a real Altair container booted as
 * an application boots it (the module's storage on Cycle, Polaris configured with a real `Gate`), the module's
 * middleware in the order its priorities give, as `ModuleMiddleware::collect()` orders them for Relay. The
 * framework's authentication is stood in for by a middleware turning the request's tenant and user headers into the
 * Polaris token attribute it would set; univeros/http itself cannot be installed beside the Elasticsearch 9 client.
 */
final class UniverosHarness implements Harness
{
    private function __construct(private readonly Container $container, private readonly RequestHandlerInterface $pipeline)
    {
    }

    #[Override]
    public static function create(SearchAdapter&SchemaAdapter&WriteAdapter $engine, string $manifestDirectory): static
    {
        $container = TestHost::boot(['ORION_ENGINE' => 'memory', 'ORION_MANIFESTS' => $manifestDirectory], static function (Container $container) use ($engine): void {
            $container->instance(EngineRegistry::class, new EngineRegistry(...[EndpointFixtureTestCase::ENGINE => $engine]));
            $container->instance(Gate::class, PolarisFixture::gate());
            $container->instance(ResponseFactoryInterface::class, new HttpFactory());
            $container->instance(StreamFactoryInterface::class, new HttpFactory());
        });
        $authenticate = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                if ($request->hasHeader(EndpointFixtureTestCase::TENANT_HEADER)) {
                    $token = new Token('t', ['sub' => $request->getHeaderLine(EndpointFixtureTestCase::USER_HEADER), 'org' => $request->getHeaderLine(EndpointFixtureTestCase::TENANT_HEADER)]);
                    $request = $request->withAttribute(RequestToken::ALTAIR_ATTRIBUTE, $token);
                }

                return $handler->handle($request);
            }
        };

        return new self($container, self::pipeline([$authenticate, ...self::moduleMiddleware($container)]));
    }

    /**
     * The module's middleware resolved through the container, ordered by priority as the front controller orders them.
     *
     * @return list<MiddlewareInterface>
     */
    private static function moduleMiddleware(Container $container): array
    {
        $entries = [];
        foreach ($container->tagged('altair.module') as $module) {
            if ($module instanceof MiddlewareProviderInterface) {
                $entries = [...$entries, ...$module->middleware()];
            }
        }
        usort($entries, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
        $middleware = [];
        foreach ($entries as $entry) {
            $instance = $entry['middleware'] instanceof MiddlewareInterface ? $entry['middleware'] : $container->get($entry['middleware']);
            \assert($instance instanceof MiddlewareInterface);
            $middleware[] = $instance;
        }

        return $middleware;
    }

    #[Override]
    public function graph(): Graph
    {
        $graph = $this->container->get(Graph::class);
        \assert($graph instanceof Graph);

        return $graph;
    }

    #[Override]
    public function handle(array $request): array
    {
        $uri = $request['path'] . (isset($request['query']) && $request['query'] !== [] ? '?' . http_build_query($request['query']) : '');
        $psr = new ServerRequest($request['method'], $uri, $request['headers'] ?? []);
        if (isset($request['query'])) {
            $params = [];
            parse_str(http_build_query($request['query']), $params);
            $psr = $psr->withQueryParams($params);
        }
        if (isset($request['raw'])) {
            $psr = $psr->withBody(Utils::streamFor($request['raw']))->withHeader('Content-Type', 'application/json');
        } elseif (isset($request['body'])) {
            $psr = $psr->withBody(Utils::streamFor(json_encode($request['body'], JSON_THROW_ON_ERROR)))->withHeader('Content-Type', 'application/json');
        }
        $response = $this->pipeline->handle($psr);
        $body = (string) $response->getBody();
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values($values);
        }

        return ['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => json_decode($body, true) ?? $body];
    }

    #[Override]
    public function as(string $tenant, string $user, Closure $work): mixed
    {
        $token = $this->container->get(RequestToken::class);
        \assert($token instanceof RequestToken);
        $request = (new ServerRequest('POST', '/seed'))->withAttribute(RequestToken::ALTAIR_ATTRIBUTE, new Token('t', ['sub' => $user, 'org' => $tenant]));
        $result = null;
        $token->process($request, new class ($work, $result) implements RequestHandlerInterface {
            /** @param Closure(): mixed $work */
            public function __construct(private readonly Closure $work, public mixed &$result)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->result = ($this->work)();

                return new Response();
            }
        });

        return $result;
    }

    #[Override]
    public static function transportHeaders(): array
    {
        return [];
    }

    /**
     * @param list<MiddlewareInterface> $middleware
     */
    private static function pipeline(array $middleware): RequestHandlerInterface
    {
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(404, ['Content-Type' => 'application/json'], '{"error":"not found"}');
            }
        };
        for ($i = count($middleware) - 1; $i >= 0; --$i) {
            $handler = new class ($middleware[$i], $handler) implements RequestHandlerInterface {
                public function __construct(private readonly MiddlewareInterface $middleware, private readonly RequestHandlerInterface $next)
                {
                }

                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return $this->middleware->process($request, $this->next);
                }
            };
        }

        return $handler;
    }
}
