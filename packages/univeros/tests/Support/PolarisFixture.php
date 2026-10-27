<?php

declare(strict_types=1);

namespace Univeros\Orion\Tests\Support;

use DateTimeImmutable;
use Polaris\Authorization\Gate;
use Polaris\Authorization\PermissionResolver;
use Polaris\Model\Membership;
use Polaris\Model\MembershipRole;
use Polaris\Model\Permission;
use Polaris\Model\Role;
use Polaris\Model\RolePermission;

/**
 * A real Polaris `Gate` over a fixed RBAC: in the organizations `acme` and `globex`, the user `u1` holds the role
 * `searcher`, which grants `products.search`; `u2` is a member of `acme` with no role. Nobody is a superadmin.
 */
final class PolarisFixture
{
    public const string ORGANIZATION = 'acme';
    public const array ORGANIZATIONS = ['acme', 'globex'];
    public const string PERMISSION = 'products.search';
    public const string ALLOWED_USER = 'u1';
    public const string OTHER_USER = 'u2';

    public static function gate(): Gate
    {
        $now = new DateTimeImmutable();
        $permission = new Permission();
        $permission->id = 'perm1';
        $permission->key = self::PERMISSION;
        $roles = [];
        $grants = [];
        $memberships = [];
        $membershipRoles = [];
        foreach (self::ORGANIZATIONS as $organization) {
            $role = new Role();
            $role->id = 'searcher@' . $organization;
            $role->organizationId = $organization;
            $role->slug = 'searcher';
            $role->name = 'Searcher';
            $role->createdAt = $now;
            $role->updatedAt = $now;
            $roles[] = $role;
            $grant = new RolePermission();
            $grant->roleId = $role->id;
            $grant->permissionId = $permission->id;
            $grants[] = $grant;
            $membership = new Membership();
            $membership->id = self::ALLOWED_USER . '@' . $organization;
            $membership->userId = self::ALLOWED_USER;
            $membership->organizationId = $organization;
            $membership->status = Membership::STATUS_ACTIVE;
            $membership->createdAt = $now;
            $membership->updatedAt = $now;
            $memberships[] = $membership;
            $membershipRole = new MembershipRole();
            $membershipRole->membershipId = $membership->id;
            $membershipRole->roleId = $role->id;
            $membershipRoles[] = $membershipRole;
        }
        $other = new Membership();
        $other->id = self::OTHER_USER . '@' . self::ORGANIZATION;
        $other->userId = self::OTHER_USER;
        $other->organizationId = self::ORGANIZATION;
        $other->status = Membership::STATUS_ACTIVE;
        $other->createdAt = $now;
        $other->updatedAt = $now;
        $memberships[] = $other;

        return new Gate(new PermissionResolver(
            new FakeRepository(),
            new FakeRepository(),
            new FakeRepository($memberships),
            new FakeRepository($membershipRoles),
            new FakeRepository($roles),
            new FakeRepository($grants),
            new FakeRepository([$permission]),
        ));
    }
}
