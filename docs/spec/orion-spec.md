> **Status:** v1, written June 2026 before `polaris-core` existed. The product design below stands; the Univeros-specific wiring (§3, §4, principle 7, and the framework halves of §9–§15 and §19) is superseded by [`revision.md`](revision.md). Decisions: [`../decisions.md`](../decisions.md).

# Orion

Deterministic, replayable index operations for any full text engine, with a thin honest hybrid query layer, that an agent can drive.

A pluggable Univeros module. Framework core: Altair. Identity: Polaris. Search: Orion. Composer package `univeros/orion`, PHP namespace `Univeros\Orion`. License follows the module convention (Polaris is Proprietary, © Univeros); confirm before publishing.

This is flagship material for Univeros, not a fast v1. Everything below is written to the bar where a skeptic can run the proof and a reindex never drops a document.

---

## 1. Positioning

Orion is not a search engine and does not try to beat one. It sits on top of Elasticsearch, Solr, OpenSearch, and Meilisearch and inherits each engine's relevance as a ceiling it cannot exceed. What it owns is the part everyone hand-rolls and gets wrong: the index lifecycle. Schema as code, drift detection, deterministic migration plans, zero downtime reindex, and a replay model where the engine is a disposable projection of your database rather than a second source of truth.

The query layer exists because an index you cannot query is half a product. It is deliberately thin: it covers the common case as a typed, introspectable contract, ships first-class hybrid retrieval (keyword plus vector) because that is the on-thesis differentiator for an agent-native framework, and hands you the engine's native query for everything else. It does not chase portable expression of every engine's full power. That ambition is what keeps every existing unified-search library shallow, and Orion does not repeat it.

Two honest sentences define the product:

- The index lifecycle is deterministic. Plan, apply, reindex, and swap are dry-runnable and reproducible, the same discipline as Univeros migrations, and every operation emits a signed entry to the append-only event log so a reindex is not just done, it is provably done.
- Ranking is not deterministic, and Orion never claims it is. BM25 scoring, shard effects, and distributed tie-breaking mean result order varies by design. Selling determinism on relevance would be dishonest.

---

## 2. Design principles

1. **Engine quality is a ceiling.** A layer on top can match the engine, never beat it. Orion sells operational consistency, verifiable lifecycle, and portability of the common case, not better results.
2. **Abstract the common case, expose a native escape hatch for the rest.** The leaky 20 percent (function_score, aggregations, nested docs, Solr streaming expressions, custom analyzer chains, engine-specific fusion) gets a typed `raw()` path that returns the same result envelope. Honest about the leak instead of pretending it is not there.
3. **Dumb adapters, smart neutral core.** Adapters implement a handful of primitive verbs. All hard orchestration (diffing, reindex, alias swap, replay, fusion) lives once in engine-neutral code. If an adapter knows how to do a zero downtime reindex, every adapter reimplements it and they all drift.
4. **The engine is a disposable projection.** The database is the source of truth. A reindex is a replay from the write outbox into a fresh physical index, not a dual-write reconciliation. This single property is what makes the indexing story better than per-engine clients rather than just a facade over them.
5. **Determinism on the lifecycle, never on ranking.** See section 1.
6. **Dependency isolation by package.** Core depends on nothing engine-specific. Each adapter is its own Composer package carrying its own client dependency.
7. **Reuse Univeros primitives, never reinvent them.** Identity through the `Altair\Http\Contracts` seam (the same one Polaris implements), crypto from `univeros/security`, async from the Messenger bridge, audit from the append-only event log, health from the doctor, telemetry from `univeros/observability`. Orion adds search; it does not add a second crypto, a second event log, or a second notion of tenant.
8. **Proof is the product.** A conformance suite, a determinism harness, and signed receipts are shipped, not described. The marketing is "run it yourself," not adjectives.

---

## 3. Package layout

Split packages so installing Orion never drags in an engine client you do not use. The framework core lives under `Altair\*`, but official modules ship under `Univeros\<Module>`, the way Polaris does, so Orion ships under `Univeros\Orion` as Composer package `univeros/orion`. Core depends only on the framework, never on an engine client.

The core also ships a dev `InMemory` adapter (not for production) so search flows, the conformance suite, and local development work with no engine standing up, mirroring how Polaris ships dev log drivers.

| Package | Depends on | Contents |
|---|---|---|
| `univeros/orion` | framework core only | Query AST, IndexSchema AST, adapter interfaces, lifecycle orchestration, reindexer, fusion, capability model, result types, module wiring, CLI, MCP tools, dev `InMemory` adapter |
| `univeros/orion-elasticsearch` | `elasticsearch/elasticsearch` | Elastic adapter |
| `univeros/orion-solr` | `solarium/solarium` | Solr adapter |
| `univeros/orion-opensearch` | `opensearch-project/opensearch-php` | OpenSearch adapter |
| `univeros/orion-meilisearch` | `meilisearch/meilisearch-php` | Meilisearch adapter |

Adapters live under `Univeros\Orion\Adapter\Elasticsearch`, etc., each in its own package with its own client dependency. Swap the adapter, swap the client dependency, the core never moves.

Core directory structure:

```
src/
  Query/            Query AST, builder, ResultSet, Hit, fusion
  Schema/           IndexSchema AST, field types, LiveSchema, MigrationPlan
  Write/            Document, BulkResult, WriteOutcome
  Adapter/          SearchAdapter, SchemaAdapter, WriteAdapter, Capability
  Lifecycle/        Planner, Reindexer, AliasManager, IndexNamer, Receipt
  Pipeline/         Outbox, IndexHandler, Indexer, Projector
  Tenancy/          TenantResolver, TenantScope, ScopedQuery
  Registry/         AdapterRegistry, IndexRegistry
  Spec/             search: block parser, byte-stable slice emitter
  Module/           Module (self-wiring), Doctor checks
  Console/          altair orion:* commands
  Mcp/              Introspection and operation tools
```

Repo layout mirrors Polaris exactly, so the ecosystem is uniform for humans and agents: `.ai/skills/orion/` (the agent skill), `api/` (the `search:` YAML specs), `database/migrations/`, `docs/search/` (the authoritative spec docs), `src/`, `tests/` (with the conformance suite), `AGENT.md`, plus `phpcs.xml`, `phpstan.neon`, `phpunit.xml.dist`. Conventions match the house style: strict types, immutability, small files, tests-first, `composer qa` before a PR, UUID-v7 entities, target coverage at or above 80 percent.

---

## 4. Module wiring

Orion is a pluggable Univeros module. Registration is one line in `config/modules.php`, after which it self-wires its routes, entities, and migrations into the host, the same contract every Univeros module follows.

```php
// config/modules.php
return [
    new Univeros\Polaris\Module(),
    new Univeros\Orion\Module(),   // self-wires search routes, outbox entity, migrations
];
```

The module registers: the search endpoint routes emitted from `search:` specs, the outbox and index-registry Cycle entities, their migrations, the Messenger handler for the indexer, the `altair orion:*` console commands, the MCP tools, and the doctor checks. Nothing is hand-wired in the host app.

---

## 5. Query model (the thin layer, now hybrid)

A neutral query AST covering the common case plus first-class hybrid retrieval, compiled per adapter to native. Fluent builder for humans, declarative construction for agents.

```php
$query = Query::on('products')
    ->match('title', $term)                          // lexical
    ->vector('embedding', $embedding, k: 50)         // semantic
    ->fuse(Fusion::reciprocalRank())                 // neutral default, see 5.1
    ->filter(Filter::range('price', lte: 100))
    ->filter(Filter::term('in_stock', true))
    ->facet('brand')
    ->sort('_score', Direction::Desc)
    ->highlight('title')
    ->paginate(page: 1, size: 20);

$results = $search->search($query); // ResultSet
```

Covered in the neutral AST: `match`, `term`, `prefix`, `phrase`; `Filter` (term, terms, range, exists, bool composition); `vector` k-NN where the engine reports the capability; `fuse` for hybrid; `sort` on fields and `_score`; portable `facet`; `highlight`; pagination (offset, and cursor where supported).

### 5.1 Fusion (the hard part of hybrid, done honestly)

Lexical and semantic results live on incompatible score scales, and those scales differ again across engines, so score-level fusion is not portable. The neutral default is **reciprocal rank fusion**, which is rank-based and needs no cross-engine normalization, so it gives a true portable promise. Weighted fusion and any engine-native fusion (Elastic RRF retriever, native hybrid pipelines) are available through the escape hatch. Orion never pretends Elastic's and Meili's score magnitudes are comparable, because they are not.

```php
->fuse(Fusion::reciprocalRank(k: 60))          // portable default
->fuse(Fusion::weighted(lexical: 0.3, vector: 0.7))  // documented as non-portable
```

Result envelope, identical across engines:

```php
final readonly class ResultSet {
    /** @var list<Hit> */
    public array $hits;
    public int $total;
    public bool $totalIsEstimate;     // Meili and others estimate; never lie about it
    public array $facets;
    public float $tookMs;
    public ?FusionTrace $fusion;      // per-strategy contribution, for the compile/explain tools
}

final readonly class Hit {
    public string $id;
    public float $score;
    public array $source;
    public array $highlights;
}
```

Native escape hatch, same envelope back:

```php
$results = $search->raw('products', fn (ElasticQueryBuilder $q) =>
    $q->functionScore(/* native Elastic DSL */)
);
```

---

## 6. Index schema model (schema as code)

The index-side equivalent of the query AST. You cannot diff what you cannot describe.

```php
$schema = IndexSchema::for('products')
    ->text('title', language: 'en', boost: 3.0)
    ->text('description', language: 'en')
    ->keyword('sku', filterable: true, sortable: true)
    ->keyword('brand', facetable: true)
    ->float('price', filterable: true, sortable: true, facetable: true)
    ->boolean('in_stock', filterable: true)
    ->vector('embedding', dims: 1536, metric: VectorMetric::Cosine)
    ->orgKey('org_id')   // resolves to the Polaris active-org claim, see section 9
    ->native('elasticsearch', fn (ElasticMapping $m) =>
        $m->analyzer('title', 'edge_ngram_folded')   // leaky 20 percent, per driver
    );
```

Neutral field types: `text`, `keyword`, `integer`, `float`, `boolean`, `date`, `geo`, `vector`, `object`/`nested` (only on adapters that report support). Analyzers expose a neutral language hint and common folding only; anything past that goes through `native()` per driver. This is the boundary where Orion refuses to pretend Elastic's char_filter to tokenizer to token_filter chain is the same object as Meili's pick-a-language model.

---

## 7. Adapter contracts

Three thin roles. Adapters implement these and nothing else. Each method is a primitive the neutral core composes.

```php
interface SearchAdapter {
    public function search(IndexName $index, Query $query): ResultSet;
    public function raw(IndexName $index, callable $native): ResultSet;
    public function supports(Capability $capability): bool;
}

interface SchemaAdapter {
    public function ensure(IndexSchema $schema): void;          // idempotent create
    public function inspect(IndexName $index): ?LiveSchema;     // read what is live
    public function plan(IndexSchema $desired): MigrationPlan;  // deterministic diff
    public function apply(MigrationPlan $plan): PendingTask;    // additive changes in place
}

interface WriteAdapter {
    public function index(IndexName $index, Document ...$docs): BulkResult;
    public function delete(IndexName $index, string ...$ids): BulkResult;
    public function flush(IndexName $index, bool $await = false): void; // refresh/commit, honest per engine
}
```

Capability probing is a first-class enum, queried by the lifecycle, the query compiler, the fusion layer, and any agent:

```php
enum Capability {
    case Vectors;
    case HybridFusion;        // native fusion; false means Orion fuses in core via RRF
    case NestedDocuments;
    case Facets;
    case Highlighting;
    case NativeAliases;       // false on Meili; alias swap emulated in core
    case AsyncWrites;         // true on Meili
    case CursorPagination;
    case FunctionScore;
    case Streaming;           // Solr streaming expressions
    case OptimisticConcurrency; // version-stamped writes, required for lossless replay
}
```

An adapter that returns false for a capability is honest, not broken. The core routes around it (emulating aliases, fusing in core, refusing an unsupported clause with a typed `UnsupportedClause` error rather than a silent wrong result).

---

## 8. The index lifecycle (the novel core)

This is the part worth building. It lives entirely in neutral code above the adapters.

### 8.1 Plan and apply

`plan()` diffs the desired `IndexSchema` against the `LiveSchema` from `inspect()` and classifies every change. Classification is **total**: every field-type and analyzer change is provably additive or breaking, and an unrecognized change fails closed as breaking rather than silently applying in place.

```php
$plan = $lifecycle->plan($schema);
$plan->isEmpty();          // no drift
$plan->additive();         // new fields, applied in place, no reindex
$plan->breaking();         // type/analyzer/dims change: requires reindex
$plan->requiresReindex();  // the trigger for 8.3
```

Additive changes apply in place. Breaking changes never mutate a live index; they route to reindex. The plan is dry-runnable and printed exactly like a Univeros migration plan.

### 8.2 Versioned indices and alias indirection

Physical indices carry a version suffix; the application only ever talks to a stable alias.

```
alias:    products            (what the app and queries use)
physical: products_v3         (current)
          products_v4         (built during reindex)
```

`AliasManager` performs the atomic swap. Native aliases on Elastic, Solr, OpenSearch. On Meilisearch the swap is emulated in the neutral layer through an indirection table, so the adapter never cares. `Capability::NativeAliases` is the only thing that differs.

### 8.3 Zero downtime reindex by replay, proven lossless

The reindex source is your database, not the old index. Because writes flow through the outbox (section 10), a reindex is a replay:

```
1. Build products_v4 from the IndexSchema (SchemaAdapter::ensure).
2. Replay the outbox into products_v4 from a known offset.
3. Tail live writes into products_v4 until caught up.
4. Atomic alias swap products -> products_v4 (AliasManager).
5. Drop products_v3 after a grace window.
```

The catch-up window between finishing the offset replay and flipping the alias is where naive implementations lose writes. Orion closes it with **version-stamped, idempotent writes**: every document carries a monotonic version (the outbox offset), writes use optimistic concurrency (`Capability::OptimisticConcurrency`), and a replayed write and a live write of the same document converge to the same final state regardless of arrival order. A concurrent-reindex lock prevents two reindexes stomping each other. Backpressure is explicit: when the engine is slower than the write rate, the drain applies bounded buffering and surfaces lag rather than dropping.

```php
$reindexer->reindex('products', to: $schema)
    ->fromOffset($offset)
    ->withGraceWindow(seconds: 300)
    ->run();   // emits a signed Receipt (section 11) on completion
```

---

## 9. Multi-tenancy via Polaris

Search is exactly where tenant data leaks if you get it wrong, and a query that returns another organization's documents would end the framework's reputation. Polaris already defines the model, so Orion consumes it rather than inventing a parallel one.

**The tenant is the Polaris organization.** Polaris' rule is that identity is global and authority is scoped to an organization, and the access token carries the active org and the resolved roles so authorization is mostly stateless. Cross-tenant access is denied by design at the identity layer. Orion's tenant scope is therefore the active-org claim on the Polaris-issued JWT, read through the same `Altair\Http\Contracts` identity seam Polaris implements. Because switch-org re-scopes the token, the scope is resolved per request from the token, not from static config.

```php
// Orion reads the active org from the Polaris-issued token, via the framework identity contract.
$scope = $tenantResolver->fromIdentity($identity); // -> TenantScope(orgId: <active org claim>)
```

**Enforcement is injected, not requested.** The `TenantScope` is injected into every compiled query beneath the public Query AST, after the neutral query is built and before it is compiled to native, so it applies uniformly across every adapter and every escape-hatch query alike. An app author cannot forget the org filter and an agent cannot omit it.

```php
// app code never writes the org filter; it is enforced from the token
$results = $search->search(Query::on('products')->match('title', $term));
// compiled query carries: AND org_id = <active org from token> automatically
```

**Endpoint and document authorization reuse Polaris, not a new check.** Each emitted search Action declares its required permission, enforced by Polaris' `AuthorizationMiddleware` exactly like every other endpoint. Where row-level visibility is needed, hits are filtered after retrieval through Polaris' programmatic `Gate`, so a document the principal may not see never reaches the response even if it matched. Orion adds no authorization machinery of its own.

**Two physical architectures, one open decision.** Index-per-org gives hard physical isolation and per-org reindex, at a cost that grows with org count. Shared-index-with-injected-org-filter scales to many orgs cheaply, with isolation resting on the injected filter plus the Gate. The right choice depends on your org scale, the one input still needed (dozens of large orgs points to index-per-org; thousands or more points to shared-index). The `TenantScope` contract is identical either way; only the physical layout it resolves to changes, so nothing else waits on this.

## 10. Indexing pipeline

Writes ride the infrastructure Univeros already has, so indexing stays fast and reindex stays a replay.

```
Cycle ORM entity events
  -> Outbox (transactional with the entity write, ordered, offset-addressable)
  -> Symfony Messenger (async drain, attribute-driven handler discovery)
  -> Indexer
  -> WriteAdapter::index / delete
```

The outbox commits in the same transaction as the entity write, so the index never drifts from a committed state and replay from any offset is well defined. The drain is a Messenger handler discovered by attribute, consistent with how the framework wires messaging. `BulkResult` is awaitable so synchronous engines (Elastic) and asynchronous ones (Meili) look identical to the caller, and partial failure routes to a dead-letter with retry rather than being swallowed:

```php
$result = $writer->index($index, ...$docs);
$result->await(timeout: 30);   // no-op on already-complete engines
$result->failures();           // per-document status -> dead-letter + retry
$result->isComplete();
```

---

## 11. Verifiable lifecycle (receipts on the existing event log)

Every lifecycle operation (apply, reindex, alias swap) emits a signed entry to the framework's append-only event log, signed with `univeros/security` crypto primitives using asymmetric signing with kid-based key rotation, the same posture Polaris uses for its JWT and JWKS keys. This reuses existing Univeros primitives rather than inventing a parallel store or a second key-management story, and it turns the determinism claim into something a skeptic can verify rather than trust.

```php
final readonly class Receipt {
    public string $operation;     // reindex | apply | alias_swap
    public IndexName $index;
    public string $fromVersion;
    public string $toVersion;
    public int $documentCount;
    public string $schemaHash;    // hash of the IndexSchema applied
    public int $fromOffset;
    public int $toOffset;
    public string $signature;     // univeros/security
    public DateTimeImmutable $at;
}
```

Because the log is append-only and the journal is rewindable, a reindex is auditable and reproducible after the fact: same offsets and same schema hash replay to the same projection. This is the verifiable-receipt worldview that runs through the rest of Univeros, applied to search.

---

## 12. Spec-driven surface (the `search:` block)

The Polaris parallel. Specs live under `api/` (for example `api/products/search.yaml`) and are scaffolded with `bin/altair spec:scaffold`, emitting a byte-stable slice (re-run on the same spec and not a byte changes), drift checked and round-trippable like every other Univeros spec.

```yaml
index: products
engine: elasticsearch          # default driver; overridable per environment
source:
  entity: App\Domain\Product
  projector: App\Search\ProductProjector   # scaffolded if absent
schema:
  title:       { type: text,    language: en, boost: 3.0 }
  description: { type: text,    language: en }
  sku:         { type: keyword, filterable: true, sortable: true }
  brand:       { type: keyword, facetable: true }
  price:       { type: float,   filterable: true, sortable: true, facetable: true }
  in_stock:    { type: boolean, filterable: true }
  embedding:   { type: vector,  dims: 1536, metric: cosine }
org_key: org_id
search:
  endpoint: GET /products/search
  query_fields: [title, description]
  hybrid: { vector_field: embedding, fusion: reciprocal_rank }
  filterable: [price, brand, in_stock]
  sortable: [price, _score]
  facets: [brand, price]
  default_sort: _score
  page_size: 20
```

Emitted slice (byte-stable):

1. `IndexSchema` registration for `products`, org key included.
2. A search Action (declaring its required permission for `AuthorizationMiddleware`), typed Input (validated q, filters, sort, page) in the Action then Input then Domain then Responder shape, and a Responder returning the normalized `ResultSet` as JSON, with errors as RFC 9457 Problem Details.
3. OpenAPI path for `GET /products/search` with documented filter and facet parameters.
4. The matching method in the generated SDK.
5. A `ProductProjector` stub if none exists.
6. Registration in the `IndexRegistry` so the CLI, MCP surface, and doctor can see it. Generated routes are relative and unversioned; the host mounts them under its own prefix, exactly as Polaris requires.

Drift between the block and the live engine schema is reported by `altair orion:drift`, same mechanic as migrations. Endpoint, schema, and docs cannot silently diverge.

---

## 13. Projections (entity to document)

**Decision baked in:** the projection is an app-registered class implementing a `Projector` contract, not a closure in config and not pure spec magic. It keeps the mapping testable in isolation, which matters because the projection is the one place app-specific logic lives.

```php
final class ProductProjector implements Projector {
    public function project(Product $product): Document {
        return new Document(
            id: (string) $product->id,
            fields: [
                'title'       => $product->title,
                'description' => $product->description,
                'sku'         => $product->sku,
                'brand'       => $product->brand->name,
                'price'       => $product->price->amount,
                'in_stock'    => $product->stock > 0,
                'org_id'      => (string) $product->orgId,
                'embedding'   => $product->embedding,
            ],
            version: $product->outboxOffset,   // version-stamp for lossless replay
        );
    }
}
```

The spec can scaffold a stub, but the class is real code you own and test. This is the one place I would let you reverse me: declare the field mapping inline in the spec and have Orion build the projector. My recommendation stands on testability.

---

## 14. CLI

Commands run under the single `altair` binary and, like every Univeros command, emit deterministic JSON an agent can branch on.

```
altair orion:plan <index>        Diff spec schema against live, print classified plan
altair orion:apply <index>       Apply additive changes; refuse breaking ones
altair orion:reindex <index>     Zero downtime reindex by replay, emit signed receipt
altair orion:drift [<index>]     Report drift across one or all registered indices
altair orion:status <index>      Live schema, alias target, doc count, pending tasks, capabilities
altair orion:flush <index>       Force refresh/commit (honest no-op where not applicable)
altair orion:verify <index>      Replay receipts and assert the projection reproduces
altair orion:capabilities <eng>  Print the capability matrix for an engine
```

Every mutating command supports `--dry-run` and prints the plan it would execute. Orion also contributes checks to `altair doctor`: engine reachable, schema in sync, no unprocessed dead-letters, no reindex lag.

---

## 15. Agent and MCP surface

Orion registers its tools with the framework's existing MCP server and ships an agent skill at `.ai/skills/orion/` plus an `AGENT.md`, mirroring Polaris, so an agent gets oriented the same way across modules. An agent operates one app against one engine, so the value to the agent is the clean typed contract plus introspection, not the multi-engine portability (that is an ops benefit for you, a separate bet, do not conflate them).

- `orion.list_indices` - registered indices, engine, alias target, doc count.
- `orion.describe_index` - field map and analyzers.
- `orion.capabilities` - what the backing engine supports, so the agent picks valid clauses.
- `orion.compile_query` - takes a neutral Query, returns the native query the adapter would send, plus the fusion trace, without executing. The agent sees exactly what Elastic receives instead of guessing.
- `orion.search` - run a typed Query, get the normalized ResultSet.
- `orion.plan` - read-only diff, report drift without mutating.

`compile_query` is the keystone: a typed query object the agent builds compiles to inspectable native DSL, far more reliable than an agent hand-writing Elastic JSON, and the transparency is the thing the raw clients never give. Mutating tools (`apply`, `reindex`) are exposed but gated behind explicit confirmation.

---

## 16. Capability matrix (honesty table)

| Capability | Elasticsearch | Solr | OpenSearch | Meilisearch |
|---|---|---|---|---|
| Neutral query AST | yes | yes | yes | yes |
| Native escape hatch | yes | yes | yes | yes |
| Vectors | yes | yes (dense_vector) | yes | yes |
| Native hybrid fusion | yes (RRF retriever) | partial | yes | no (core RRF) |
| Nested documents | yes | partial | yes | no |
| Facets | yes | yes | yes | yes |
| Highlighting | yes | yes | yes | yes |
| Native aliases | yes | yes (collection alias) | yes | no (emulated) |
| Async writes | no | no | no | yes (await) |
| Cursor pagination | yes | yes (cursorMark) | yes | no |
| Function score | yes | yes | yes | no |
| Optimistic concurrency | yes | yes (_version_) | yes | partial |
| Streaming expressions | no | yes | no | no |

Where a capability is false, the query compiler raises a typed `UnsupportedClause` at build time (surfaced as an RFC 9457 Problem Details response), or the core supplies the behavior (core RRF when native fusion is absent), never a silent wrong result.

---

## 17. Determinism boundary

| Axis | Deterministic | Why |
|---|---|---|
| Schema plan and apply | yes | Pure diff of declared schema against live schema |
| Reindex and alias swap | yes | Replay from an ordered outbox offset, atomic swap, signed receipt |
| Drift detection | yes | Declared spec versus inspected live schema |
| Document projection | yes | Pure function entity to Document |
| Receipt verification | yes | Same offsets and schema hash replay to the same projection |
| Result ranking | no | BM25, shard effects, distributed tie-breaking are statistical by design |

Determinism is a lifecycle property. Ranking is not, and Orion does not pretend otherwise.

---

## 18. The verification regime (proof is the product)

Shipped as part of the package, because the marketing is "run it yourself."

1. **Conformance suite.** One suite every adapter must pass before it ships. Adapters cannot drift in observable behavior. This is the gate between build steps, replacing "it works" with "it passes conformance."
2. **Query equivalence (property-based).** A neutral query compiles to semantically equivalent native across every engine, asserted over generated query space, not a handful of examples.
3. **Determinism harness.** Plan, apply, and reindex run repeatedly and assert identical outcomes and identical receipts for identical inputs.
4. **Fault injection.** The engine is killed mid-reindex, mid-bulk, and mid-swap; the harness asserts no document loss, clean recovery, and a receipt that still verifies.
5. **Tenancy isolation tests.** Generated multi-tenant corpora assert no query, including escape-hatch queries, ever returns a foreign tenant's document.

The public CI report of these suites is the strongest marketing asset Orion has, and it is the same verifiable worldview as the rest of Univeros.

---

## 19. Build sequence

Ordered to validate the abstraction early and stress it before it ossifies, and tracked as phased epics on GitHub the way Polaris is. The gate between every step is the conformance and determinism suite, not "it runs."

1. **Core ASTs and fusion.** Query AST (with hybrid and RRF), IndexSchema AST, result and document types. Unit-testable in isolation.
2. **Elasticsearch adapter.** Richest engine first, so the neutral model is shaped against real capability.
3. **Schema lifecycle.** Planner, total classification, AliasManager, versioned indices. Prove plan and apply on Elastic.
4. **Outbox replay reindexer plus receipts.** The novel core. Prove lossless zero downtime reindex end to end, with version-stamped idempotent writes and fault injection.
5. **Tenancy.** TenantScope from Polaris context, injected enforcement, isolation tests. Done early because it is correctness, not a feature.
6. **Solr adapter.** Second Lucene-family engine, different schema model (managed-schema) and alias model (collection alias). First real test the neutral schema model is not secretly Elastic-shaped.
7. **Query and fusion hardening.** Compile to Elastic and Solr, validate escape hatch and core RRF on both, lock `UnsupportedClause`.
8. **Spec block and byte-stable emitter.** The `search:` slice, drift command, projector scaffolding.
9. **MCP surface.** `compile_query` first, then the rest.
10. **OpenSearch adapter.** Near-free after Elastic (fork). Cheap confidence the split-package model holds.
11. **Meilisearch adapter.** The real stress test: async writes, emulated aliases, no native fusion, partial optimistic concurrency. If the abstraction survives Meili without leaking its limits upward or forcing Lucene assumptions downward, it is sound.

Engines that fall out of the abstraction at steps 6 and 11 are the signal to fix the neutral model, not to special-case the core.

---

## 20. Open decisions

1. **Org scale.** The one input still needed, because it picks index-per-org versus shared-index-with-injected-filter (section 9). The `TenantScope` contract is identical either way; only the physical layout changes.
2. **Projection declaration.** Baked in as an app-registered `Projector` class (section 13) for testability. Reversible to spec-declared inline mapping if you prefer fewer files over isolated tests.
3. **Naming flourish, optional.** The three adapter roles map to the three belt stars of Orion: Search as Alnitak, Schema as Alnilam, Write as Mintaka. On-theme and skippable. Interface names stay descriptive regardless, because real code needs discoverable names over cute ones.

---

*Univeros / Orion. Build the lifecycle, keep the query layer thin and honest, scope every query to the Polaris org, sign every operation, let the agent drive.*
