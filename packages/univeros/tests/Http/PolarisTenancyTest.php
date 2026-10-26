<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Http;

use Altair\Container\Container;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Orion\Orion;
use Orion\Query\Searcher;
use Orion\Tenancy\NoTenant;
use PHPUnit\Framework\TestCase;
use Polaris\Authorization\Gate;
use Polaris\Token\Token;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use stdClass;
use Univeros\Orion\Http\RequestToken;
use Univeros\Orion\Module;
use Univeros\Orion\Tests\Support\TestHost;

/**
 * With univeros/polaris installed, the tenant is the active organization on the request's Polaris token (v1 §9):
 * captured after authentication, read for every operation, forgotten when the request ends.
 */
final class PolarisTenancyTest extends TestCase
{
    public function testTheTenantIsTheRequestTokensActiveOrganization(): void
    {
        $container = self::withPolaris(['ORION_ENGINE' => 'memory']);
        $token = new Token('jwt', ['sub' => 'u1', 'org' => 'org-a']);

        $tenant = $this->during($container, (new ServerRequest('GET', '/products'))->withAttribute(RequestToken::ALTAIR_ATTRIBUTE, $token), static function () use ($container): string {
            return self::orion($container)->graph()->tenancy()->resolver?->resolve()->tenant ?? 'single';
        });

        self::assertSame('org-a', $tenant);
    }

    public function testPolarisOwnAttributeIsReadToo(): void
    {
        $container = self::withPolaris(['ORION_ENGINE' => 'memory']);

        $tenant = $this->during($container, (new ServerRequest('GET', '/products'))->withAttribute(RequestToken::POLARIS_ATTRIBUTE, new Token('jwt', ['org' => 'org-b'])), static function () use ($container): string {
            return self::orion($container)->graph()->tenancy()->resolver?->resolve()->tenant ?? 'single';
        });

        self::assertSame('org-b', $tenant);
    }

    public function testOutsideARequestThereIsNoTenant(): void
    {
        $container = self::withPolaris(['ORION_ENGINE' => 'memory']);
        $this->during($container, (new ServerRequest('GET', '/'))->withAttribute(RequestToken::ALTAIR_ATTRIBUTE, new Token('jwt', ['org' => 'org-a'])), static fn(): string => '');

        $this->expectException(NoTenant::class);

        self::orion($container)->graph()->tenancy()->resolver?->resolve();
    }

    public function testSingleTenancyIsRefusedWherePolarisDecidesTenants(): void
    {
        $this->expectExceptionMessage('Tenancy::single()');

        self::orion(self::withPolaris(['ORION_ENGINE' => 'memory', 'ORION_TENANCY' => 'single']));
    }

    public function testPolarisTenancyNeedsPolaris(): void
    {
        $this->expectExceptionMessage('univeros/polaris');

        self::orion(TestHost::boot(['ORION_ENGINE' => 'memory', 'ORION_TENANCY' => 'polaris']));
    }

    public function testTheTokenIsCapturedAfterAuthenticationOnEveryRoute(): void
    {
        self::assertContains(['middleware' => RequestToken::class, 'priority' => RequestToken::priority()], (new Module())->middleware());
        // Without univeros/http (as here), the framework's documented anchors: ACTION (1000) - 10, after DISPATCHER (500) + 5.
        self::assertSame(990, RequestToken::priority());
    }

    public function testTheSearcherIsBound(): void
    {
        self::assertInstanceOf(Searcher::class, TestHost::boot()->get(Searcher::class));
    }

    /**
     * @param array<string, string> $env
     */
    private static function withPolaris(array $env): Container
    {
        // What univeros/polaris binds; the module only asks whether it is there.
        return TestHost::boot($env, static function (Container $container): void {
            $container->singleton(Gate::class, static fn(): stdClass => new stdClass());
        });
    }

    private static function orion(Container $container): Orion
    {
        $orion = $container->get(Orion::class);
        \assert($orion instanceof Orion);

        return $orion;
    }

    /**
     * Runs `$inside` as the request's handler, behind the module's middleware.
     *
     * @template T
     * @param callable(): T $inside
     * @return T
     */
    private function during(Container $container, ServerRequestInterface $request, callable $inside): mixed
    {
        $middleware = $container->get(RequestToken::class);
        \assert($middleware instanceof RequestToken);
        $handler = new class ($inside) implements RequestHandlerInterface {
            public mixed $result = null;

            /** @param callable(): mixed $inside */
            public function __construct(private $inside)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->result = ($this->inside)();

                return new Response();
            }
        };
        $middleware->process($request, $handler);

        return $handler->result;
    }
}
