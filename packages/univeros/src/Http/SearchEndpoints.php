<?php

declare(strict_types=1);

namespace Univeros\Orion\Http;

use Altair\Container\Container;
use Altair\Persistence\Exception\InvalidConfigurationException;
use Orion\Manifest\Manifest;
use Orion\Psr15\Authorizer;
use Orion\Psr15\SearchMiddleware;
use Orion\Wiring\Graph;
use Override;
use Polaris\Authorization\Gate;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Univeros\Orion\Module;

use function class_exists;
use function is_string;
use function sprintf;

/**
 * The module's middleware serving the `search:` endpoints through `orion-search/psr15` (docs/spec/revision.md §5),
 * placed just after {@see RequestToken}, so a request's tenant is known before its search runs. The permission an
 * endpoint declares is checked through the Polaris `Gate` when univeros/polaris is configured, through a bound
 * {@see Authorizer} otherwise. Built on the first request, so misconfiguration never fails boot. PSR-17 factories come
 * from the container, else from laminas-diactoros, which the Univeros skeleton ships.
 */
final class SearchEndpoints implements MiddlewareInterface
{
    public const string PREFIX = 'ORION_ENDPOINT_PREFIX';
    /** The container id of the route action the module registers for every endpoint. */
    public const string ACTION = 'orion.search.action';
    private const string ALTAIR_ACTION = 'Altair\\Http\\Base\\Action';
    private const string DIACTOROS_RESPONSES = 'Laminas\\Diactoros\\ResponseFactory';
    private const string DIACTOROS_STREAMS = 'Laminas\\Diactoros\\StreamFactory';

    private ?SearchMiddleware $middleware = null;

    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Just after the token capture, still inside the action.
     */
    public static function priority(): int
    {
        return RequestToken::priority() + 1;
    }

    /**
     * The framework's route action for a declared endpoint, so the dispatcher routes the request and authentication
     * runs before this middleware answers it; {@see UnservedSearch} is its domain, never reached. The action class is
     * univeros/http's, which only a host installs (its CORS dependency pins psr/http-message 1, which the
     * Elasticsearch 9 client excludes), so it is named, not imported.
     */
    public static function action(): object
    {
        $class = self::ALTAIR_ACTION;
        if (!class_exists($class)) {
            throw new InvalidConfigurationException('The search endpoints are routed through univeros/http, which is not installed.');
        }

        return new $class(UnservedSearch::class);
    }

    /**
     * The routes of the declared endpoints under the prefix, as `[method, path, action]`.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function routes(Manifest $manifest, ?string $prefix): array
    {
        $routes = [];
        foreach ($manifest->endpoints() as $declaration) {
            $endpoint = $declaration->endpoint;
            if ($endpoint !== null) {
                $routes[] = [$endpoint->method, SearchMiddleware::prefix((string) $prefix) . $endpoint->path, self::ACTION];
            }
        }

        return $routes;
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $this->middleware()->process($request, $handler);
    }

    private function middleware(): SearchMiddleware
    {
        if ($this->middleware === null) {
            $graph = $this->get(Graph::class);
            $prefix = Module::env($this->container)->get(self::PREFIX);
            $this->middleware = new SearchMiddleware(
                $graph->manifest(),
                $graph->searcher(),
                $this->factory(ResponseFactoryInterface::class, self::DIACTOROS_RESPONSES),
                $this->factory(StreamFactoryInterface::class, self::DIACTOROS_STREAMS),
                $this->authorizer(),
                $this->container->has(LoggerInterface::class) ? $this->get(LoggerInterface::class) : $graph->logger(),
                is_string($prefix) ? $prefix : '',
            );
        }

        return $this->middleware;
    }

    /**
     * A bound authorizer wins; where Polaris decides, the Gate; otherwise none, and an endpoint declaring a
     * permission answers 500 until one is bound (fail closed).
     */
    private function authorizer(): ?Authorizer
    {
        if ($this->container->has(Authorizer::class)) {
            return $this->get(Authorizer::class);
        }
        if (!$this->container->has(Gate::class)) {
            return null;
        }
        $gate = $this->get(Gate::class);

        return new GateAuthorizer($this->get(RequestToken::class), $gate->allows(...));
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param string $fallback a class implementing `$id`, used when installed
     * @return T
     */
    private function factory(string $id, string $fallback): object
    {
        if ($this->container->has($id)) {
            return $this->get($id);
        }
        if (class_exists($fallback)) {
            $factory = new $fallback();
            if ($factory instanceof $id) {
                return $factory;
            }
        }

        throw new InvalidConfigurationException(sprintf('The search endpoints need a PSR-17 %s: bind one, or install laminas/laminas-diactoros.', $id));
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function get(string $id): object
    {
        $value = $this->container->get($id);

        return $value instanceof $id ? $value : throw new InvalidConfigurationException(sprintf('The container binds %s to something else.', $id));
    }
}
