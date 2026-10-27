# Multi-tenancy

Serve many tenants (organizations, customers, workspaces) from one index without any of them seeing, overwriting
or deleting another's documents. Orion resolves the current tenant for every search and every outbox write,
filters every query on it, the native escape hatch included, and stamps it into every document.

## Choose: one tenant or a resolver

Tenancy has no default. Every `Config` says which it is:

```php
use Orion\Tenancy\Tenancy;

Tenancy::single();                    // one tenant: no filter, no org key
Tenancy::resolvedBy($resolver);       // a tenant per operation, from your request context
```

Use `single()` when the application's search data belongs to one party, even if the application has many users.
Use a resolver when documents belong to different tenants who must never see each other's. There is no third
option and no fallback: a resolver that finds no tenant stops the operation.

Orion implements tenancy as a **shared index**: every tenant's documents live in the same index, separated by the
org key. An index per tenant is not built in. The reasoning, and the trade-off against index-per-tenant, is in
[spec §9](../spec/orion-spec.md) and the WP6 entries of the [decisions log](../decisions.md).

## Declare an org key on every index

Under a resolver, every index must name the field that holds the tenant:

```php
$schema = IndexSchema::for('notes')->text('body')->orgKey('org_id');
```

```yaml
# api/notes/search.yaml
index: notes
schema:
  body: { type: text }
org_key: org_id
```

If the schema does not declare `org_id`, `orgKey()` adds it as a filterable keyword. `apply()` refuses an index
without an org key under a resolver, and an index with one under `single()`. The org key cannot be changed by a
reindex once the index holds documents.

## Write a resolver

A resolver answers one question, per operation: who is the tenant now?

```php
use Orion\Tenancy\NoTenant;
use Orion\Tenancy\TenantResolver;
use Orion\Tenancy\TenantScope;

final class CurrentOrganization implements TenantResolver
{
    public function __construct(private readonly RequestContext $context)
    {
    }

    public function resolve(): TenantScope
    {
        $organization = $this->context->organizationId()
            ?? throw new NoTenant('No organization in this request.');

        return new TenantScope($organization);
    }
}

$config = new Config(
    storage: $storage,
    engines: $engines,
    tenancy: Tenancy::resolvedBy(new CurrentOrganization($context)),
);
```

Orion calls `resolve()` for every search and every outbox write, never caches it, so a request that switches
organizations is honoured at once. Read the tenant from something the client cannot forge (a verified token, an
attribute your authentication set), never from a header or query parameter.

A tenant id is printable UTF-8, at most 128 bytes, without `:` (Orion uses it as a separator).

## What changes under a resolver

Your code stays the same; Orion adds the tenant underneath it.

**Writes.** `$graph->outbox()->index($notes, 'n1', ['body' => '...'])` records the document under the engine id
`<tenant>:n1` and sets `org_id` to the tenant. Two tenants can both have a document `n1`; they never collide. A
document that sets `org_id` to another tenant is refused. `delete()` removes only the current tenant's `n1`. Under
a tenant, a document id has that much less room: 255 bytes minus the tenant and the separator.

**Searches.** `$graph->searcher()->search(...)` ANDs `Filter::term('org_id', <tenant>)` beneath the query, where no
filter the caller writes can widen it, and does the same for `compile()` and `raw()`. Every hit is checked to be the
tenant's before it is returned, and its id comes back as your id (`n1`), not the engine's.

```php
$resolver->tenant = 'acme';
$graph->searcher()->search(Query::on('notes')->match('body', 'secret'));   // only acme's notes; ids as written

$resolver->tenant = null;
$graph->searcher()->search(Query::on('notes'));                             // throws NoTenant
```

**The escape hatch.** Under a resolver, each adapter refuses native query shapes that could read beyond the
matched documents (aggregations, suggesters, Solr local parameters and joins, OpenSearch search pipelines, and so
on), because those would let one tenant learn about another's data. Single tenancy keeps the full native query.

**Lifecycle and drain.** `apply`, `reindex`, `prune`, `verify` and the drain work on whole indices and need no
tenant: the outbox entries already carry it. Running them from the CLI or a worker is fine.

**Agent tools.** The `Orion\Tools` tools run as the host process. Under a resolver, a `search` or `compile_query`
call with no tenant in context fails with `NoTenant`; an agent acting for a tenant needs a resolver the host wires
to that tenant.

## The tenant from Polaris

With [Polaris](https://github.com/univeros/polaris-core), the tenant is the active organization on the verified
access token. `orion-search/polaris` provides the resolver:

```sh
composer require orion-search/polaris
```

```php
use Orion\Polaris\ActiveOrganization;
use Orion\Tenancy\Tenancy;

$tenancy = Tenancy::resolvedBy(new ActiveOrganization(
    fn() => $currentRequest->getAttribute('polaris.token'),   // the verified Polaris\Contract\TokenInterface, or null
));
```

It reads the token's `org` claim for every operation. With no token, or a token without an active organization
(as Polaris issues right after login, until `POST /auth/switch-org`), it throws `NoTenant`. The Univeros module
wires this for you when `univeros/polaris` is installed ([Frameworks](frameworks.md#univeros)).

## The tenant from a PSR-15 request

Without Polaris, `Orion\Psr15\RequestTenant` is both a middleware and a resolver: the middleware reads the tenant
from each request through your closure and holds it while the request is handled, and the resolver hands it to
Orion.

```php
use Orion\Psr15\RequestTenant;
use Psr\Http\Message\ServerRequestInterface;

$tenant = new RequestTenant(
    fn(ServerRequestInterface $request): ?string => $request->getAttribute('organization_id'),   // set by your authentication
);

$config = new Config(storage: $storage, engines: $engines, tenancy: Tenancy::resolvedBy($tenant));
// add $tenant to the middleware pipeline after authentication and before anything that searches or writes
```

Outside a request, or when the closure returns null, it throws `NoTenant`. It holds one request at a time:
runtimes that interleave requests in one process (fibers, async servers) are not supported.

## Testing it

`Orion\Testing\SettableResolver` is a resolver a test switches by hand, and `TenancyIsolationTestCase` is the suite
every engine adapter passes to prove no query, native or neutral, returns another tenant's document. See
[Testing](testing.md#testing-multi-tenant-code).
