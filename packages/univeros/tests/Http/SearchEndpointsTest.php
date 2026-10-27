<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Http;

use Altair\Persistence\Exception\InvalidConfigurationException;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use Orion\Manifest\Loader;
use Orion\Psr15\Forbidden;
use Orion\Tenancy\NoTenant;
use PHPUnit\Framework\TestCase;
use Polaris\Token\Token;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Univeros\Orion\Http\GateAuthorizer;
use Univeros\Orion\Http\RequestToken;
use Univeros\Orion\Http\SearchEndpoints;
use Univeros\Orion\Module;
use Orion\Testing\Endpoint\EndpointFixtureTestCase;
use Univeros\Orion\Tests\Support\PolarisFixture;
use Univeros\Orion\Tests\Support\TestHost;

use function getenv;
use function putenv;

final class SearchEndpointsTest extends TestCase
{
    public function testTheEndpointsSitJustAfterTheTokenCapture(): void
    {
        $middleware = (new Module())->middleware();

        self::assertContains(['middleware' => RequestToken::class, 'priority' => RequestToken::priority()], $middleware);
        self::assertContains(['middleware' => SearchEndpoints::class, 'priority' => SearchEndpoints::priority()], $middleware);
        self::assertSame(RequestToken::priority() + 1, SearchEndpoints::priority());
    }

    public function testEveryDeclaredEndpointIsARouteUnderThePrefix(): void
    {
        $directory = EndpointFixtureTestCase::manifestDirectory();
        $previous = [getenv('ORION_MANIFESTS'), getenv('ORION_ENDPOINT_PREFIX')];
        putenv('ORION_MANIFESTS=' . $directory);
        putenv('ORION_ENDPOINT_PREFIX=api/');
        try {
            $routes = (new Module())->routes();
        } finally {
            putenv('ORION_MANIFESTS' . ($previous[0] === false ? '' : '=' . $previous[0]));
            putenv('ORION_ENDPOINT_PREFIX' . ($previous[1] === false ? '' : '=' . $previous[1]));
        }

        self::assertSame([['POST', '/api/items/search', SearchEndpoints::ACTION], ['GET', '/api/products/search', SearchEndpoints::ACTION]], $routes);
        self::assertSame(['GET', '/products/search', SearchEndpoints::ACTION], SearchEndpoints::routes((new Loader($directory))->load(), null)[1]);
    }

    public function testTheRouteActionNeedsUniverosHttp(): void
    {
        self::assertTrue(TestHost::boot()->has(SearchEndpoints::ACTION));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('univeros/http');

        SearchEndpoints::action();
    }

    public function testTheGateDecidesForTheRequestsToken(): void
    {
        $token = new RequestToken();
        $authorizer = new GateAuthorizer($token, PolarisFixture::gate()->allows(...));
        $request = static fn(string $user): ServerRequestInterface => (new ServerRequest('GET', '/products/search'))
            ->withAttribute(RequestToken::ALTAIR_ATTRIBUTE, new Token('t', ['sub' => $user, 'org' => PolarisFixture::ORGANIZATION]));
        $outcomes = [];
        foreach ([PolarisFixture::ALLOWED_USER, PolarisFixture::OTHER_USER] as $user) {
            $token->process($request($user), self::handler(static function () use ($authorizer, $user, &$outcomes): void {
                try {
                    $authorizer->authorize(new ServerRequest('GET', '/products/search'), PolarisFixture::PERMISSION);
                    $outcomes[$user] = 'allowed';
                } catch (Forbidden) {
                    $outcomes[$user] = 'forbidden';
                }
            }));
        }

        self::assertSame(['u1' => 'allowed', 'u2' => 'forbidden'], $outcomes);
    }

    public function testWithoutATokenTheRequestIsUnauthorized(): void
    {
        $authorizer = new GateAuthorizer(new RequestToken(), PolarisFixture::gate()->allows(...));

        $this->expectException(NoTenant::class);

        $authorizer->authorize(new ServerRequest('GET', '/products/search'), PolarisFixture::PERMISSION);
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
