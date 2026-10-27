<?php

declare(strict_types=1);

namespace Orion\Psr15;

use Orion\Manifest\Manifest;
use Orion\Query\Searcher;
use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

use function array_keys;
use function implode;
use function sort;
use function trim;

/**
 * Serves every endpoint the `search:` manifests declare, mounted under the host's prefix (v1 §12: routes are relative,
 * the host mounts them); anything else passes through. A declared path asked with another method is 405 with the
 * methods allowed, so a client learns the route exists. Handlers are built once, on the first request.
 */
final class SearchMiddleware implements MiddlewareInterface
{
    /** @var array<string, array<string, SearchHandler>>|null handlers by path, then method */
    private ?array $handlers = null;
    private readonly string $prefix;

    public function __construct(
        private readonly Manifest $manifest,
        private readonly Searcher $searcher,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
        private readonly ?Authorizer $authorizer = null,
        private readonly ?LoggerInterface $logger = null,
        string $prefix = '',
    ) {
        $this->prefix = self::prefix($prefix);
    }

    /**
     * A mount prefix as a path: `api`, `/api` and `/api/` all mount under `/api`; empty mounts at the root.
     */
    public static function prefix(string $prefix): string
    {
        $trimmed = trim($prefix, '/');

        return $trimmed === '' ? '' : '/' . $trimmed;
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $byMethod = $this->handlers()[$request->getUri()->getPath()] ?? null;
        if ($byMethod === null) {
            return $handler->handle($request);
        }
        $search = $byMethod[$request->getMethod()] ?? null;
        if ($search === null) {
            $allowed = array_keys($byMethod);
            sort($allowed);

            return (new Problem($this->responses, $this->streams))
                ->respond(405, 'method-not-allowed', 'Method not allowed', 'This endpoint is served by ' . implode(', ', $allowed) . '.')
                ->withHeader('Allow', implode(', ', $allowed));
        }

        return $search->handle($request);
    }

    /**
     * @return array<string, array<string, SearchHandler>>
     */
    private function handlers(): array
    {
        if ($this->handlers === null) {
            $handlers = [];
            foreach ($this->manifest->endpoints() as $declaration) {
                $search = new SearchHandler($declaration, $this->searcher, $this->responses, $this->streams, $this->authorizer, $this->logger);
                $handlers[$this->prefix . $search->endpoint()->path][$search->endpoint()->method] = $search;
            }
            $this->handlers = $handlers;
        }

        return $this->handlers;
    }
}
