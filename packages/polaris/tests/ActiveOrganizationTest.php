<?php

declare(strict_types=1);

namespace Orion\Polaris\Tests;

use Orion\Polaris\ActiveOrganization;
use Orion\Tenancy\NoTenant;
use Orion\Tenancy\TenantResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polaris\Contract\TokenInterface;
use Polaris\Token\Token;

final class ActiveOrganizationTest extends TestCase
{
    public function testTheTenantIsTheTokensActiveOrganization(): void
    {
        $resolver = new ActiveOrganization(static fn(): TokenInterface => new Token('jwt', ['sub' => 'u1', 'org' => '0199a6f0-7c1e-7b8a-9f00-0000000000a1']));

        self::assertInstanceOf(TenantResolver::class, $resolver);
        self::assertSame('0199a6f0-7c1e-7b8a-9f00-0000000000a1', $resolver->resolve()->tenant);
    }

    public function testTheTokenIsReadForEveryOperation(): void
    {
        $org = 'org-a';
        $resolver = new ActiveOrganization(static function () use (&$org): TokenInterface {
            return new Token('jwt', ['org' => $org]);
        });

        $first = $resolver->resolve()->tenant;
        $org = 'org-b';

        self::assertSame(['org-a', 'org-b'], [$first, $resolver->resolve()->tenant]);
    }

    /**
     * @return iterable<string, array{TokenInterface|null}>
     */
    public static function withoutAnOrganization(): iterable
    {
        yield 'no token' => [null];
        yield 'no org claim' => [new Token('jwt', ['sub' => 'u1'])];
        yield 'no active org, as right after login' => [new Token('jwt', ['sub' => 'u1', 'org' => null])];
        yield 'an empty org' => [new Token('jwt', ['org' => ''])];
        yield 'an org that is not a string' => [new Token('jwt', ['org' => ['a', 'b']])];
        yield 'an org Orion cannot hold as a tenant' => [new Token('jwt', ['org' => 'acme:eu'])];
    }

    #[DataProvider('withoutAnOrganization')]
    public function testWithoutAnActiveOrganizationThereIsNoTenant(?TokenInterface $token): void
    {
        $this->expectException(NoTenant::class);

        (new ActiveOrganization(static fn(): ?TokenInterface => $token))->resolve();
    }
}
