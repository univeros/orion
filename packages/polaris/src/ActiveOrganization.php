<?php

declare(strict_types=1);

namespace Orion\Polaris;

use Closure;
use InvalidArgumentException;
use Orion\Tenancy\NoTenant;
use Orion\Tenancy\TenantResolver;
use Orion\Tenancy\TenantScope;
use Override;
use Polaris\Contract\TokenInterface;

use function is_string;

/**
 * The tenant is the Polaris organization (v1 §9): the active-org claim (`org`) of the verified access token, read for
 * every operation, because switching organizations issues a new token. Polaris issues tokens without an active org
 * (right after login, until `POST /auth/switch-org`); with one of those, or none, there is no tenant.
 */
final readonly class ActiveOrganization implements TenantResolver
{
    public const string CLAIM = 'org';

    /**
     * @param Closure(): ?TokenInterface $token the current request's verified token, as the host's authentication set it
     */
    public function __construct(private Closure $token)
    {
    }

    #[Override]
    public function resolve(): TenantScope
    {
        $token = ($this->token)() ?? throw new NoTenant('No Polaris token in this context: search and index writes need an authenticated request.');
        $organization = $token->getMetadata(self::CLAIM);
        if (!is_string($organization) || $organization === '') {
            throw new NoTenant('The Polaris token has no active organization: switch to one (POST /auth/switch-org) first.');
        }

        try {
            return new TenantScope($organization);
        } catch (InvalidArgumentException $invalid) {
            throw new NoTenant('The Polaris token\'s active organization is not an id Orion can scope to: ' . $invalid->getMessage(), 0, $invalid);
        }
    }
}
