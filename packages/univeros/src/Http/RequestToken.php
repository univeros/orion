<?php

declare(strict_types=1);

namespace Univeros\Orion\Http;

use Override;
use Polaris\Contract\TokenInterface;
use Polaris\Http\Attributes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function constant;
use function defined;
use function is_int;

/**
 * Holds the verified Polaris token of the request being handled, for the tenant resolver: set after authentication,
 * restored when the request ends, so a sequential worker (php-fpm, RoadRunner, FrankenPHP) never carries one request's
 * tenant into the next. It is one shared object: a runtime that interleaves requests in one process (Swoole
 * coroutines, fibers) would mix tenants, and is not supported. Orion's operations belong in actions, or in middleware
 * after {@see priority()}; before it there is no tenant.
 */
final class RequestToken implements MiddlewareInterface
{
    /** `Altair\Http\Contracts\TokenInterface::TOKEN_KEY`, where the framework's authentication puts the token. */
    public const string ALTAIR_ATTRIBUTE = 'altair:http:token';
    /** Where Polaris' own middleware puts it. */
    public const string POLARIS_ATTRIBUTE = Attributes::TOKEN;
    /** The framework's pipeline anchors, read from univeros/http when it is installed. */
    private const string ACTION = 'Altair\\Http\\Support\\MiddlewarePriority::ACTION';
    private const int DOCUMENTED_ACTION = 1000;

    /**
     * Just inside the action: after authentication (`DISPATCHER + 5`) and authorization, so the token is verified.
     */
    public static function priority(): int
    {
        $action = defined(self::ACTION) ? constant(self::ACTION) : self::DOCUMENTED_ACTION;

        return (is_int($action) ? $action : self::DOCUMENTED_ACTION) - 10;
    }

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
