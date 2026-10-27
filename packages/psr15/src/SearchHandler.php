<?php

declare(strict_types=1);

namespace Orion\Psr15;

use InvalidArgumentException;
use LogicException;
use Orion\Adapter\UnsupportedClause;
use Orion\Manifest\Declaration;
use Orion\Manifest\Endpoint;
use Orion\Query\Searcher;
use Orion\Tenancy\NoTenant;
use Override;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * One declared endpoint (v1 §12): the request read into the neutral query, the caller's permission checked through
 * the host's {@see Authorizer} when the endpoint declares one, the query run through the scoped {@see Searcher} (the
 * only query path, so the tenant filter is beneath it), and the {@see Envelope} back as JSON. Failures are RFC 9457
 * problems with the failure contract of docs/decisions.md (WP6): no tenant is 401, a missing permission 403, input
 * that does not fit 400 (an unsupported clause named as such), configuration that does not fit or an engine failure
 * 500 with the cause logged, never echoed.
 */
final readonly class SearchHandler implements RequestHandlerInterface
{
    private Problem $problems;
    private LoggerInterface $logger;
    private Endpoint $endpoint;

    public function __construct(
        private Declaration $declaration,
        private Searcher $searcher,
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
        private ?Authorizer $authorizer = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->endpoint = $declaration->endpoint ?? throw new LogicException(sprintf('%s declares no endpoint.', $declaration->schema->index->value));
        $this->problems = new Problem($responses, $streams);
        $this->logger = $logger ?? new NullLogger();
    }

    public function endpoint(): Endpoint
    {
        return $this->endpoint;
    }

    #[Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            if ($this->endpoint->permission !== null) {
                $authorizer = $this->authorizer ?? throw new LogicException(sprintf('The endpoint %s declares the permission %s, but no Authorizer is configured to check it.', $this->endpoint->path, $this->endpoint->permission));
                $authorizer->authorize($request, $this->endpoint->permission);
            }
            $query = SearchRequest::from($request, $this->endpoint, $this->declaration->schema)->query;
            $body = json_encode(Envelope::of($this->searcher->search($query), $query->page), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (NoTenant $none) {
            return $this->problems->respond(401, 'no-tenant', 'Unauthorized', 'This request has no tenant: authenticate, and select an organization.');
        } catch (Forbidden $forbidden) {
            return $this->problems->respond(403, 'forbidden', 'Forbidden', sprintf('This endpoint needs the permission %s.', (string) $this->endpoint->permission));
        } catch (UnsupportedClause $unsupported) {
            return $this->problems->respond(400, 'unsupported-clause', 'Unsupported clause', $unsupported->getMessage(), ['capability' => $unsupported->capability->name]);
        } catch (InvalidArgumentException $invalid) {
            return $this->problems->respond(400, 'invalid-request', 'Invalid request', $invalid->getMessage());
        } catch (Throwable $failure) {
            $this->logger->error('The search endpoint {path} failed: {reason}', ['path' => $this->endpoint->path, 'reason' => $failure->getMessage(), 'exception' => $failure]);

            return $this->problems->respond(500, 'search-failed', 'Search failed', 'The search could not be completed; the failure has been logged.');
        }

        return Problem::uncached($this->responses->createResponse(200))->withHeader('Content-Type', 'application/json')->withBody($this->streams->createStream($body));
    }
}
