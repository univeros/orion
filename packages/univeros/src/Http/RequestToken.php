<?php

declare(strict_types=1);

namespace Univeros\Orion\Http;

use Override;
use Polaris\Contract\TokenInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Holds the verified Polaris token of the request being handled, for the tenant resolver: set after authentication,
 * restored when the request ends, so a long-running worker never carries one request's tenant into the next.
 */
final class RequestToken implements MiddlewareInterface
{
    /** `Altair\Http\Support\MiddlewarePriority::ACTION - 10`: after authentication (`DISPATCHER + 5`), before the action. */
    public const int PRIORITY = 990;
    /** `Altair\Http\Contracts\TokenInterface::TOKEN_KEY`, where the framework's authentication puts the token. */
    public const string ALTAIR_ATTRIBUTE = 'altair:http:token';
    /** `Polaris\Http\Attributes::TOKEN`, where Polaris' own middleware puts it. */
    public const string POLARIS_ATTRIBUTE = 'polaris.token';

    private ?TokenInterface $current = null;

    #[Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $previous = $this->current;
        $this->current = self::from($request);
        try {
            return $handler->handle($request);
        } finally {
            $this->current = $previous;
        }
    }

    public function current(): ?TokenInterface
    {
        return $this->current;
    }

    private static function from(ServerRequestInterface $request): ?TokenInterface
    {
        foreach ([self::POLARIS_ATTRIBUTE, self::ALTAIR_ATTRIBUTE] as $attribute) {
            $token = $request->getAttribute($attribute);
            if ($token instanceof TokenInterface) {
                return $token;
            }
        }

        return null;
    }
}
