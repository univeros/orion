<?php

declare(strict_types=1);

namespace Orion\Psr15\Tests;

use Closure;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use Orion\Adapter\SchemaAdapter;
use Orion\Adapter\SearchAdapter;
use Orion\Adapter\WriteAdapter;
use Orion\Orion;
use Orion\Psr15\Authorizer;
use Orion\Psr15\Forbidden;
use Orion\Psr15\RequestTenant;
use Orion\Psr15\SearchMiddleware;
use Orion\Registry\EngineRegistry;
use Orion\Tenancy\Tenancy;
use Orion\Testing\Endpoint\EndpointFixtureTestCase;
use Orion\Testing\Endpoint\Harness;
use Orion\Testing\InMemoryStorage;
use Orion\Wiring\Config;
use Orion\Wiring\Graph;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function array_values;
use function count;
use function http_build_query;
use function is_string;
use function json_decode;
use function json_encode;
use function parse_str;

use const JSON_THROW_ON_ERROR;

/**
 * The endpoint fixtures through the bare PSR-15 pipeline: an authentication stand-in that turns the request's
 * tenant and user headers into attributes (as a host's authentication would), {@see RequestTenant}, then
 * {@see SearchMiddleware}, then a 404. `u1` holds every permission, nobody else holds any.
 */
final class PipelineHarness implements Harness
{
    public const string TENANT_ATTRIBUTE = 'tenant';
    public const string USER_ATTRIBUTE = 'user';

    private function __construct(private readonly Graph $graph, private readonly RequestTenant $tenant, private readonly RequestHandlerInterface $pipeline)
    {
    }

    #[Override]
    public static function create(SearchAdapter&SchemaAdapter&WriteAdapter $engine, string $manifestDirectory): static
    {
        $tenant = new RequestTenant(static function (ServerRequestInterface $request): ?string {
            $tenant = $request->getAttribute(self::TENANT_ATTRIBUTE);

            return is_string($tenant) ? $tenant : null;
        });
        $graph = Orion::create(new Config(
            storage: new InMemoryStorage(),
            engines: new EngineRegistry(...[EndpointFixtureTestCase::ENGINE => $engine]),
            tenancy: Tenancy::resolvedBy($tenant),
            manifestDirectories: [$manifestDirectory],
        ))->graph();
        $factory = new HttpFactory();
        $authorizer = new class () implements Authorizer {
            public function authorize(ServerRequestInterface $request, string $permission): void
            {
                if ($request->getAttribute(PipelineHarness::USER_ATTRIBUTE) !== EndpointFixtureTestCase::ALLOWED_USER) {
                    throw new Forbidden($permission);
                }
            }
        };
        $search = new SearchMiddleware($graph->manifest(), $graph->searcher(), $factory, $factory, $authorizer);
        $authenticate = new class () implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $request = $request->withAttribute(PipelineHarness::USER_ATTRIBUTE, $request->getHeaderLine(EndpointFixtureTestCase::USER_HEADER));
                if ($request->hasHeader(EndpointFixtureTestCase::TENANT_HEADER)) {
                    $request = $request->withAttribute(PipelineHarness::TENANT_ATTRIBUTE, $request->getHeaderLine(EndpointFixtureTestCase::TENANT_HEADER));
                }

                return $handler->handle($request);
            }
        };

        return new self($graph, $tenant, self::pipeline([$authenticate, $tenant, $search]));
    }

    #[Override]
    public function graph(): Graph
    {
        return $this->graph;
    }

    #[Override]
    public function handle(array $request): array
    {
        $uri = $request['path'] . (isset($request['query']) && $request['query'] !== [] ? '?' . http_build_query($request['query']) : '');
        $psr = new ServerRequest($request['method'], $uri, $request['headers'] ?? []);
        if (isset($request['query'])) {
            $psr = $psr->withQueryParams(self::fromQueryString(http_build_query($request['query'])));
        }
        if (isset($request['raw'])) {
            $psr = $psr->withBody(Utils::streamFor($request['raw']))->withHeader('Content-Type', 'application/json');
        } elseif (isset($request['body'])) {
            $psr = $psr->withBody(Utils::streamFor(json_encode($request['body'], JSON_THROW_ON_ERROR)))->withHeader('Content-Type', 'application/json');
        }
        $response = $this->pipeline->handle($psr);
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = array_values($values);
        }

        return ['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => $decoded ?? $body];
    }

    #[Override]
    public function as(string $tenant, string $user, Closure $work): mixed
    {
        $request = (new ServerRequest('POST', '/seed'))->withAttribute(self::TENANT_ATTRIBUTE, $tenant)->withAttribute(self::USER_ATTRIBUTE, $user);
        $result = null;
        $this->tenant->process($request, new class ($work, $result) implements RequestHandlerInterface {
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
     * The query string as PHP parses it into `$_GET`, nested arrays included.
     *
     * @return array<string, mixed>
     */
    private static function fromQueryString(string $query): array
    {
        $params = [];
        parse_str($query, $params);

        /** @var array<string, mixed> $params */
        return $params;
    }

    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public static function pipeline(array $middleware): RequestHandlerInterface
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
