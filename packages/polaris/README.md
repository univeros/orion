# orion-search/polaris

Orion tenancy from [Polaris](https://github.com/univeros/polaris-core). The tenant is the active organization on the
verified Polaris access token (its `org` claim), read for every search and every index write.

```sh
composer require orion-search/polaris
```

```php
use Orion\Polaris\ActiveOrganization;
use Orion\Tenancy\Tenancy;

$config = new Config(
    storage: $storage,
    engines: $engines,
    tenancy: Tenancy::resolvedBy(new ActiveOrganization(fn() => $currentRequest->getAttribute('polaris.token'))),
);
```

The closure returns the current request's verified token (any `Polaris\Contract\TokenInterface`), or null. Without a
token, or with one that has no active organization (as Polaris issues right after login), resolving throws
`Orion\Tenancy\NoTenant`, and nothing is searched or recorded.

Every index then declares an org key (`org_key` in its `search:` manifest). Orion stamps it from the token on every
write and filters on it beneath every query, the escape hatch included. The Univeros module (`univeros/orion`) wires
all of this when `univeros/polaris` is installed.

Tenancy in depth, with or without Polaris: the [Multi-tenancy guide](../../docs/guides/multi-tenancy.md).
