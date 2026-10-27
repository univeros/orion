<?php

declare(strict_types=1);

namespace Orion\Psr15;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The host's authorization, asked once per request for the permission the endpoint declares (v1 §9: endpoint
 * authorization reuses the host's, Orion adds none). The Univeros module binds it to the Polaris `Gate`.
 */
interface Authorizer
{
    /**
     * @throws Forbidden when the request's caller does not hold the permission
     */
    public function authorize(ServerRequestInterface $request, string $permission): void;
}
