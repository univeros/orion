<?php

declare(strict_types=1);

namespace Univeros\Orion\Http;

use Closure;
use Orion\Psr15\Authorizer;
use Orion\Psr15\Forbidden;
use Orion\Tenancy\NoTenant;
use Override;
use Polaris\Contract\TokenInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The permission a `search:` endpoint declares, checked through the Polaris `Gate` for the request's verified token
 * (v1 §9: endpoint authorization reuses Polaris, Orion adds none). The token is the one {@see RequestToken} holds,
 * so the check runs inside the action, after authentication; without a token the request has no tenant either, and is
 * unauthorized.
 */
final readonly class GateAuthorizer implements Authorizer
{
    /**
     * @param Closure(TokenInterface, string): bool $allows `Gate::allows(...)`
     */
    public function __construct(private RequestToken $token, private Closure $allows)
    {
    }

    #[Override]
    public function authorize(ServerRequestInterface $request, string $permission): void
    {
        $token = $this->token->current() ?? throw new NoTenant('No verified token in this context: ' . $permission . ' cannot be checked.');
        if (!($this->allows)($token, $permission)) {
            throw new Forbidden($permission);
        }
    }
}
