<?php

declare(strict_types=1);

namespace Orion\Psr15\Tests;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Orion\Psr15\RequestTenant;
use Orion\Tenancy\NoTenant;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function is_string;

final class RequestTenantTest extends TestCase
{
    public function testTheTenantIsTheRequestsWhileItIsHandledAndNoneAfter(): void
    {
        $tenant = self::tenant();
        $seen = null;

        $tenant->process((new ServerRequest('GET', '/'))->withAttribute('org', 'acme'), self::handler(static function () use ($tenant, &$seen): void {
            $seen = $tenant->resolve()->tenant;
        }));

        self::assertSame('acme', $seen);
        $this->expectException(NoTenant::class);
        $tenant->resolve();
    }

    public function testARequestWithoutATenantHasNone(): void
    {
        $tenant = self::tenant();
        $failed = false;

        $tenant->process(new ServerRequest('GET', '/'), self::handler(static function () use ($tenant, &$failed): void {
            try {
                $tenant->resolve();
            } catch (NoTenant) {
                $failed = true;
            }
        }));

        self::assertTrue($failed);
    }

    public function testATenantOrionCannotScopeToIsNoTenant(): void
    {
        $tenant = self::tenant();
        $message = '';

        $tenant->process((new ServerRequest('GET', '/'))->withAttribute('org', 'acme:1'), self::handler(static function () use ($tenant, &$message): void {
            try {
                $tenant->resolve();
            } catch (NoTenant $none) {
                $message = $none->getMessage();
            }
        }));

        self::assertStringContainsString('not an id Orion can scope to', $message);
    }

    public function testThePreviousTenantIsRestoredEvenWhenTheHandlerThrows(): void
    {
        $tenant = self::tenant();
        $inner = null;

        $tenant->process((new ServerRequest('GET', '/'))->withAttribute('org', 'outer'), self::handler(static function () use ($tenant, &$inner): void {
            try {
                $tenant->process((new ServerRequest('GET', '/'))->withAttribute('org', 'inner'), self::handler(static function (): void {
                    throw new \RuntimeException('boom');
                }));
            } catch (\RuntimeException) {
                $inner = $tenant->resolve()->tenant;
            }
        }));

        self::assertSame('outer', $inner);
    }

    private static function tenant(): RequestTenant
    {
        return new RequestTenant(static function (ServerRequestInterface $request): ?string {
            $org = $request->getAttribute('org');

            return is_string($org) ? $org : null;
        });
    }

    /**
     * @param callable(): void $inside
     */
    private static function handler(callable $inside): RequestHandlerInterface
    {
        return new class ($inside) implements RequestHandlerInterface {
            /** @param callable(): void $inside */
            public function __construct(private $inside)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->inside)();

                return new Response();
            }
        };
    }
}
