# Testing

Test an application that uses Orion without a search server or a database: wire it on the in-memory engine and
in-memory storage, assert on hits, facets and the compiled query, and pin time and tenants. For adapters, the
`orion-search/testing` package also holds the conformance suites every engine and storage passes.

## Install

```sh
composer require --dev orion-search/testing
```

It brings `InMemoryStorage`, clocks, a settable tenant resolver and the conformance test cases, on PHPUnit 12. The
in-memory engine itself is in `orion-search/core`.

## Wire Orion for a test

```php
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Orion;
use Orion\Registry\EngineRegistry;
use Orion\Tenancy\Tenancy;
use Orion\Testing\FixedClock;
use Orion\Testing\InMemoryStorage;
use Orion\Wiring\Config;

$graph = Orion::create(new Config(
    storage: new InMemoryStorage(),                         // Orion's tables over PHP arrays; nothing to install
    engines: new EngineRegistry(memory: new InMemoryEngine()),
    tenancy: Tenancy::single(),
    clock: new FixedClock(),                                // timestamps a test can assert
))->graph();
```

Build a fresh graph in `setUp()`: both in-memory parts start empty, so tests never share state.

| Helper | What it is |
| --- | --- |
| `Orion\Adapter\InMemory\InMemoryEngine` | Every engine verb over PHP arrays; passes the engine conformance suite. No aliases, nested fields or native fusion; ASCII-only lowercasing, no stemming |
| `Orion\Testing\InMemoryStorage` | The reference `Storage`: Orion's tables, transactions that roll back, the same comparison rules as SQL |
| `Orion\Testing\FixedClock` | A clock that always reads one instant (`2026-01-01 00:00:00` by default) |
| `Orion\Testing\SettableClock` | A clock you move with `advance('+10 minutes')`, for leases and prune grace windows |
| `Orion\Testing\SettableResolver` | A tenant resolver you switch with `$resolver->tenant = 'acme'`; `null` means no tenant |

## A test of your search

```php
use Orion\IndexName;
use Orion\Query\Filter;
use Orion\Query\Query;
use Orion\Schema\IndexSchema;
use PHPUnit\Framework\TestCase;

final class ProductSearchTest extends TestCase
{
    private Graph $graph;
    private IndexName $products;

    protected function setUp(): void
    {
        $this->graph = /* as above */;
        $this->graph->planner('memory')->apply(
            IndexSchema::for('products')->text('title', language: 'en')->keyword('brand', filterable: true, facetable: true),
        );
        $this->products = IndexName::of('products');
    }

    public function testFindsShoesByBrand(): void
    {
        $this->index('p1', ['title' => 'Trail running shoe', 'brand' => 'Acme']);
        $this->index('p2', ['title' => 'Hiking boot', 'brand' => 'Peak']);

        $results = $this->graph->searcher()->search(
            Query::on('products')->match('title', 'shoe')->filter(Filter::term('brand', 'Acme'))->facet('brand'),
        );

        self::assertSame(['p1'], array_map(static fn($hit) => $hit->id, $results->hits));
        self::assertSame(['brand' => ['Acme' => 1]], $results->facets);
    }

    public function testADeletedProductIsNotFound(): void
    {
        $this->index('p1', ['title' => 'Trail running shoe', 'brand' => 'Acme']);
        $this->graph->outbox()->delete($this->products, 'p1');
        $this->graph->drain()->run($this->products);

        self::assertSame(0, $this->graph->searcher()->search(Query::on('products')->match('title', 'shoe'))->total);
    }

    private function index(string $id, array $fields): void
    {
        $this->graph->outbox()->index($this->products, $id, $fields);
        $this->graph->drain()->run($this->products);
    }
}
```

Use the schema your application declares (load it from the manifest with
`Orion\Manifest\Loader::read('api/products/search.yaml')->schema`), so a test fails when a query uses a field the
schema does not allow. Drain explicitly in tests (`$graph->drain()->run($index)`) rather than relying on the
dispatcher, so it is clear when documents become searchable.

Relevance order is not part of any engine's promise. When several hits match, assert on the set of ids, or sort
explicitly, rather than on the order relevance happens to produce.

## Test the transaction, on a real connection

`InMemoryStorage` rolls back like a database, but to prove your code records the outbox entry on the same
connection and transaction as the entity, use `PdoStorage` on an in-memory SQLite database and your real write path:

```php
public function testTheOutboxEntryRollsBackWithTheEntity(): void
{
    $pdo = new PDO('sqlite::memory:');
    Orion\Pdo\Installer::install($pdo);
    $graph = Orion::create(new Config(
        storage: new Orion\Pdo\PdoStorage($pdo),
        engines: new EngineRegistry(memory: new InMemoryEngine()),
        tenancy: Tenancy::single(),
    ))->graph();

    $pdo->beginTransaction();
    $graph->outbox()->index(IndexName::of('products'), 'p1', ['title' => 'Trail running shoe']);
    $pdo->rollBack();

    self::assertSame(0, $graph->outbox()->size(IndexName::of('products')));
}
```

## Test projectors on their own

A projector is a plain class ([Indexing](indexing.md#projections-entity-to-document)). Test it without Orion: build
an entity, project it, assert on `$document->id` and `$document->fields`. That test is where field-mapping mistakes
are cheapest to catch.

## Assert on the compiled query

`compile()` shows the query after Orion bound it to the schema and added the tenant filter, without running it,
which makes it a precise assertion for query-building code:

```php
public function testTheTenantFilterIsCompiledBeneathTheQuery(): void
{
    $resolver = new Orion\Testing\SettableResolver('acme');
    $graph = Orion::create(new Config(
        storage: new InMemoryStorage(),
        engines: new EngineRegistry(memory: new InMemoryEngine()),
        tenancy: Tenancy::resolvedBy($resolver),
    ))->graph();
    $graph->planner('memory')->apply(IndexSchema::for('notes')->text('body')->orgKey('org_id'));

    $compiled = $graph->searcher()->compile(Query::on('notes')->match('body', 'plan'));

    self::assertSame([['type' => 'term', 'field' => 'org_id', 'value' => 'acme']], $compiled->query->toArray()['filters']);
}
```

On a real engine, `$compiled->native` is the request the engine would receive.

## Testing multi-tenant code

With `SettableResolver`, switch tenants inside one test to prove isolation in your own code paths:

```php
$resolver->tenant = 'acme';
$graph->outbox()->index($notes, 'n1', ['body' => 'acme plan']);
$resolver->tenant = 'globex';
$graph->outbox()->index($notes, 'n1', ['body' => 'globex plan']);
$graph->drain()->run($notes);

$resolver->tenant = 'acme';
// a search now returns only acme's n1

$resolver->tenant = null;
$this->expectException(Orion\Tenancy\NoTenant::class);
$graph->searcher()->search(Query::on('notes'));
```

## Integration tests against a real engine

Run the same tests against a real engine by swapping the registry, for example
`MeilisearchEngine::connect(getenv('MEILISEARCH_URL'))`, and use a unique index name per test run so runs do not
collide. Writes become searchable after the engine's refresh: flush before asserting, as `bin/orion flush` does:

```php
$current = $graph->aliases()->current($products);
$graph->engines()->get($current->engine)->flush($graph->aliases()->targetOf($current), await: true);
```

The repository's own [`compose.yaml`](../../compose.yaml) starts every engine and database Orion is tested
against, on non-default ports.

## Suites for adapter authors

If you write an engine or storage adapter, extend the case that proves it. Each is abstract; implement the hooks
listed:

| Test case | Proves | Implement |
| --- | --- | --- |
| `Orion\Testing\EngineConformanceTestCase` | Schema create, inspect and apply; versioned writes and deletes; the neutral query's portable semantics; the scoped escape hatch; `UnsupportedClause` for every capability reported false | `createEngine()`, `nativeMatchAll()` |
| `Orion\Testing\StorageConformanceTestCase` | The storage contract and that the outbox row commits and rolls back with a row written on the host's own connection | `createStorage()`, `insertOnHostConnection()`, `inHostTransaction()` |
| `Orion\Testing\TenancyIsolationTestCase` | No query, native or neutral, returns, overwrites or deletes another tenant's document | `createEngine()`, `nativeMatchAll()` |
| `Orion\Testing\LifecycleDeterminismTestCase` | Plan, apply and reindex are reproducible | `createEngine()` |
| `Orion\Testing\FaultInjectionTestCase` | Reindex is lossless when the engine dies mid-bulk, before or after a verb | `createEngine()` |
| `Orion\Testing\Equivalence\QueryEquivalenceTestCase` | Seeded random queries answer as the in-memory oracle does | `createEngine()` |
| `Orion\Testing\Endpoint\EndpointFixtureTestCase` | A host serves the recorded `search:` endpoint fixtures exactly, for two tenants on one shared index | `harnessClass()` (an `Orion\Testing\Endpoint\Harness`) |

The existing adapters' tests under `packages/*/tests` show each one in use. Why these suites exist is in
[spec §18](../spec/orion-spec.md).
