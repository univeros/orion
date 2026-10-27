<?php

declare(strict_types=1);

namespace Orion\Psr15;

use Closure;
use InvalidArgumentException;
use Orion\Tenancy\NoTenant;
use Orion\Tenancy\TenantResolver;
use Orion\Tenancy\TenantScope;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The tenant of the request being handled, for hosts without Polaris: a middleware that reads it from the request
 * through the host's closure (an attribute the host's authentication set, never a header a client can write), holds
 * it while the request is handled and restores the previous one when it ends, so a sequential worker never carries
 * one request's tenant into the next; and the resolver that hands it to Orion, or `NoTenant` outside a request
 * or when the closure found none. One shared object, as `univeros/orion`'s `RequestToken`: runtimes interleaving
 * requests in one process are not supported.
 */
final class RequestTenant implements MiddlewareInterface, TenantResolver
{
    private ?string $current = null;

    /**
     * @param Closure(ServerRequestInterface): ?string $tenant the request's tenant, or null when it has none
     */
    public function __construct(private readonly Closure $tenant)
    {
    }

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $previous = $this->current;
        $this->current = ($this->tenant)($request);
        try {
            return $handler->handle($request);
        } finally {
            $this->current = $previous;
        }
    }

    #[Override]
    public function resolve(): TenantScope
    {
        $tenant = $this->current ?? throw new NoTenant('No tenant in this context: search and index writes need an authenticated request.');
        try {
            return new TenantScope($tenant);
        } catch (InvalidArgumentException $invalid) {
            throw new NoTenant('The request\'s tenant is not an id Orion can scope to: ' . $invalid->getMessage(), 0, $invalid);
        }
    }
}
