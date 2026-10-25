# Orion — revision for the framework-agnostic layout

**Status:** v2 delta over [`orion-spec.md`](orion-spec.md) (v1, June 2026), decided 2026-10-25. v1 remains the
product specification: positioning, design principles, the query and schema models, adapter contracts,
lifecycle, tenancy semantics, receipts, CLI surface, MCP tools, capability matrix, determinism boundary and
verification regime all stand. This document replaces the parts of v1 that assumed Orion lives inside Univeros
(v1 §3 package layout, §4 module wiring, §2 principle 7, the framework-specific halves of §9, §10, §11, §12,
§14, §15, and §19 build sequence). Where the two disagree, this document wins. Decisions are logged in
[`../decisions.md`](../decisions.md).

v1 was written before `polaris-core` existed. Polaris was later extracted out of Univeros into a framework-free
core plus a thin Univeros module, at the cost of a full extraction program. Orion is built that way from the
start, in one repository, so the extraction never has to happen.

---

## 1. Shape

One repository, `github.com/univeros/orion`, holding every package under `packages/`, split-published later
to one read-only repository per package (`univeros/orion.<package>`), the way `polaris-core` publishes
`polaris/*`. Nothing is developed outside this repository: the Univeros module is `packages/univeros`, proven
by the same CI run as the core, released on the same version line.

| Package | Composer name | Namespace | Contents |
|---|---|---|---|
| `packages/core` | `orion-search/core` | `Orion\` | Query and schema ASTs, result and document types, adapter contracts, `Capability`, Planner, AliasManager, Reindexer, outbox, receipts and signer port, tenancy contracts, index registry, `search:` manifest loader, health checks, dev `InMemory` engine, `Orion::create()` |
| `packages/testing` | `orion-search/testing` | `Orion\Testing\` | In-memory storage, fake clock, engine and storage conformance test cases, fault-injection harness |
| `packages/pdo` | `orion-search/pdo` | `Orion\Pdo\` | Storage adapter over PDO (PostgreSQL, MySQL, SQLite), schema installer and diff |
| `packages/psr15` | `orion-search/psr15` | `Orion\Psr15\` | The search endpoint as a PSR-15 handler, tenant middleware, RFC 9457 problems |
| `packages/cli` | `orion-search/cli` | `Orion\Cli\` | `bin/orion`: plan, apply, reindex, drift, status, flush, verify, capabilities, doctor, outbox:drain |
| `packages/polaris` | `orion-search/polaris` | `Orion\Polaris\` | `TenantResolver` from the Polaris token's active-org claim; hit filter through the Polaris `Gate` |
| `packages/elasticsearch` | `orion-search/elasticsearch` | `Orion\Elasticsearch\` | Engine adapter on `elasticsearch/elasticsearch` |
| `packages/solr` | `orion-search/solr` | `Orion\Solr\` | Engine adapter on `solarium/solarium` |
| `packages/opensearch` | `orion-search/opensearch` | `Orion\OpenSearch\` | Engine adapter on `opensearch-project/opensearch-php` |
| `packages/meilisearch` | `orion-search/meilisearch` | `Orion\Meilisearch\` | Engine adapter on `meilisearch/meilisearch-php` |
| `packages/univeros` | `univeros/orion` | `Univeros\Orion\` | The Univeros module (§5) |
| `packages/mcp` (later) | `orion-search/mcp` | `Orion\Mcp\` | Standalone MCP server over the core tools |
| `packages/laravel`, `symfony`, `yii` (later, on demand) | `orion-search/<host>` | `Orion\<Host>\` | Host adapters |

Packages are created when their work package starts (§7), not up front. Only `core` and `testing` exist at WP0.

### Core dependencies

`php >=8.3`, `ext-sodium`, `psr/clock`, `psr/event-dispatcher`, `psr/log`, `psr/simple-cache`, `symfony/uid`,
`symfony/yaml`. Nothing else. `psr15` adds the PSR-7/15/17 interfaces; `cli` adds `symfony/console`; each
engine package adds its own client; `polaris` adds `polaris/core`; `univeros` adds the `univeros/*` packages.

---

## 2. Dependency isolation

`bin/check-imports` runs in `composer qa` and CI and fails on:

- `Altair\`, `Cycle\` or `Univeros\` anywhere except `packages/univeros` and `examples/univeros`;
- `Polaris\` anywhere except `packages/polaris` and `packages/univeros`;
- an engine client namespace (`Elastic\`, `OpenSearch\`, `Solarium\`, `Meilisearch\`) outside its adapter package;
- a host framework namespace outside its adapter package.

This is v1 principle 6 ("dependency isolation by package") made mechanical, and it is what makes v1 principle 7
("reuse Univeros primitives") invert: core defines ports, hosts bind implementations.

---

## 3. Ports (the `Config` seam)

`Orion::create(new Orion\Wiring\Config(...))` builds the object graph without a container, as `Polaris::create()`
does. Every port is nullable with a working core default except the three that are a trust or correctness
boundary, which the application must pass explicitly.

| Port | Required | Core default | What the Univeros module binds |
|---|---|---|---|
| `Storage` (row-level adapter: outbox, registry, alias indirection, receipts) | yes | none; `orion-search/testing` has the in-memory one | an adapter over Cycle's `DatabaseInterface`, the host's live connection (§4) |
| `Engines` (named engine adapters) | yes | `InMemory` for dev and tests | the configured engine package |
| `Tenancy` | yes | none: `Tenancy::single()` or a `TenantResolver`, never implicit | `orion-search/polaris` over the token the framework middleware sets |
| `Dispatcher` (async drain) | no | synchronous, or the CLI worker | Symfony Messenger through the framework's `HandlerRegistry` |
| `ReceiptSigner` | no | Ed25519 through libsodium, with `kid` | the same, keyed from the host's key material |
| `ClockInterface`, `LoggerInterface`, `EventDispatcherInterface`, `CacheInterface` | no | system clock, null logger, null dispatcher, in-memory cache | the container's bindings |
| `Metrics` | no | null | the framework `Meter` |
| `manifestDirectories` (`search:` YAML) | no | `api/` | the host's `api/` |

`ReceiptSigner` and the receipts table exist because v1 §11 assumed a framework append-only event log and
asymmetric signing in `univeros/security`. Neither exists: the framework has no event log and `univeros/security`
is a symmetric encrypter. Core therefore owns an append-only, hash-chained `orion_receipt` table (the
`polaris/audit` shape) and signs each receipt with Ed25519; `orion verify` walks the chain and checks every
signature. The Univeros module changes only where the key comes from.

---

## 4. The same-connection invariant

v1 §8.3 and §10 rest on the outbox committing in the same transaction as the entity write. That holds only if
the outbox row is written on the **same database connection** as the entity. A second connection, which is how
`univeros/polaris` reads its own tables beside Cycle, silently breaks the lossless-reindex claim.

Therefore:

1. `Storage` must be implementable over the host's live connection: Cycle's `DatabaseInterface` in Univeros,
   PDO elsewhere, DBAL or Laravel's connection in their adapters. `orion-search/pdo` is the reference, not the only
   path.
2. `Outbox::record()` runs inside the caller's transaction on that same `Storage`; it never opens one of its own.
3. The storage conformance suite includes a test that writes an entity row and an outbox row in one transaction,
   rolls back, and asserts neither exists. An adapter that cannot pass it is not an Orion storage adapter.

This is the first thing WP1 settles, before any engine adapter exists.

---

## 5. The Univeros module (`packages/univeros`, `univeros/orion`)

One line in `config/modules.php`, exactly as v1 §4 promises. The module is glue; it adds no search behaviour.

- `Module::apply()` binds one `Orion` built from the container and the environment: `Storage` over Cycle's
  connection, the engine named by `ORION_ENGINE`, `Tenancy` through `orion-search/polaris` when
  `univeros/polaris` is present (else `ORION_TENANCY=single` must be set explicitly), `Dispatcher` over the
  framework bus, clock, logger, cache, dispatcher and `Meter` when bound.
- Routes: the `search:` endpoints served through `orion-search/psr15` behind a module middleware, like
  `PolarisMiddleware`; the required permission is enforced by the Polaris authorization middleware when Polaris
  is installed.
- Migration: one Cycle migration calling the storage installer, so `bin/altair db:migrate` installs the tables.
- Console: the `orion-search/cli` commands registered as `orion:*` on `bin/altair`. Every mutating command
  honours `--dry-run` and `--json`; `orion:drift --check` exits non-zero on drift so it sits in CI beside
  `spec:lint` and `db:migrate:status`.
- Doctor: each core health check wrapped as an `Altair\Doctor` check with `dependsOn(['database_reachable'])`,
  joining `bin/altair doctor`, `--only`, `--skip`, `--format=json` and `--fix`:

  | Check | Failing status | `--fix` |
  |---|---|---|
  | `orion_engine_reachable` | error | none; reports the connection setting |
  | `orion_registry_current` | warn | registers `search:` indices missing from the registry |
  | `orion_schema_drift` | error when breaking, warn when additive | applies the additive plan only; breaking drift reports `bin/altair orion:reindex <index>` as the agent action, never auto-fixed |
  | `orion_alias_resolves` | error | none |
  | `orion_outbox_lag` | warn above threshold | none; reports the drain command |
  | `orion_dead_letters` | error when any | none; reports the retry command |
  | `orion_receipts_verify` | error | none |

- MCP: the core tools adapted to `Altair\Mcp\Contracts\McpToolInterface`; mutating tools gated as v1 §15 says.
- AgentSpec: a `ManifestContributorInterface` section listing indices, engines and commands.
- Scaffold: the byte-stable `search:` slice of v1 §12 (Action, Input, Responder, OpenAPI path, SDK method,
  projector stub) is emitted here, as a Univeros extra. Core only loads the `search:` YAML at runtime and
  serves it; it generates nothing.

The module is proven by the harness pattern: `ORION_HARNESS=Univeros\Orion\Tests\Harness` replays the
functional suite and the recorded endpoint fixtures through a real Altair container and Relay pipeline, in the
same `composer qa` as the bare PSR-15 harness.

---

## 6. Layout of `packages/core/src`

```
Query/        Query AST, builder, Filter, Fusion (RRF in core), ResultSet, Hit, FusionTrace
Schema/       IndexSchema AST, field types, LiveSchema, MigrationPlan, change classification (total)
Write/        Document, BulkResult, WriteOutcome
Adapter/      SearchAdapter, SchemaAdapter, WriteAdapter, Capability, UnsupportedClause, InMemory/
Lifecycle/    Planner, Reindexer, AliasManager, IndexNamer, ReindexLock
Receipt/      Receipt, ReceiptSigner (port), SodiumSigner, ReceiptStore, Verifier
Outbox/       Outbox, Entry, Drain, Dispatcher (port), SyncDispatcher, DeadLetters
Tenancy/      TenantScope, TenantResolver (port), Tenancy (single | resolver), ScopeInjector
Storage/      Storage (port), Schema (tables as data), Installer contract
Registry/     IndexRegistry, EngineRegistry
Manifest/     search: YAML loader and validation
Projection/   Projector (contract), Document mapping
Health/       Check, CheckResult, the seven checks
Wiring/       Config, Graph, Orion::create()
```

`Module/`, `Console/`, `Mcp/` and the slice emitter of v1 §3 are not in core; they live in `cli`, `univeros`
and `mcp`.

---

## 7. Work packages

The gate between every WP is `composer qa` green plus the conformance and determinism suites, never "it runs".
No pull requests: work lands on `main` in coherent commits authored by the owner alone (no attribution
trailers); record decisions in `docs/decisions.md`, append only; the WP's epic is closed with a comment listing
each acceptance criterion and how it was verified. v1 §19's order is kept, with the Univeros module pulled forward to WP5,
because the module is what shakes out the ports while they are still cheap to change.

| WP | Scope | Acceptance |
|---|---|---|
| 0 | Repository: root QA, `check-imports`, `core` and `testing` skeletons, docs, CI | `composer qa` green; `check-imports` has its own tests |
| 1 | Core ASTs and types; `Storage` port with in-memory adapter; same-connection conformance test; `Orion::create()` | Query and schema ASTs round-trip; RRF fuses deterministically; storage conformance passes in memory |
| 2 | `orion-search/pdo`; Elasticsearch adapter; engine conformance suite | PDO passes storage conformance on SQLite and PostgreSQL in CI; Elastic passes engine conformance against a service container |
| 3 | Schema lifecycle: Planner with total classification, versioned indices, AliasManager | Plan is a pure diff; every change classified; unknown change fails closed as breaking; determinism harness green on Elastic |
| 4 | Outbox, Reindexer, receipts, `cli` | Fault injection (engine killed mid-bulk, mid-reindex, mid-swap) loses no document; receipts verify; `bin/orion` emits deterministic JSON |
| 5 | **Univeros module** `packages/univeros`, `examples/univeros`, harness | One line installs it; Cycle-backed storage passes the same-connection test; doctor checks and `--fix` behave per §5; walkthrough green in CI |
| 6 | Tenancy: `Tenancy`, `ScopeInjector`, `orion-search/polaris`, isolation tests | No query, escape hatch included, returns a foreign tenant's document; booting without an explicit tenancy choice fails |
| 7 | Solr adapter | Passes engine conformance; the schema model needed no Lucene-shaped change, or the change is logged |
| 8 | Query and fusion hardening; `UnsupportedClause` locked; `psr15` endpoint and `search:` manifest | Property-based query equivalence on Elastic and Solr; recorded endpoint fixtures through the PSR-15 and Univeros harnesses |
| 9 | MCP tools in core, `compile_query` first; module adaptation | An agent compiles a neutral query to inspectable native DSL without executing |
| 10 | OpenSearch adapter | Passes conformance; proves the split-package model is cheap |
| 11 | Meilisearch adapter | Passes conformance with emulated aliases, core RRF, async writes awaited; nothing leaked upward |

Host adapters for Laravel, Symfony and Yii are not scheduled; they are added as packages when asked for, on the
`polaris-core` adapter pattern.

---

## 8. Open decisions carried from v1

1. **Org scale** (v1 §20.1): index-per-org versus shared index with injected filter. `TenantScope` is identical
   either way; decide before WP6.
2. **Projection declaration** (v1 §20.2): app-registered `Projector` class stands; the contract is
   `project(object $entity): Document`, framework-free.
3. **Vendor name**: `orion-search/*` because the Packagist vendor `orion` is owned by a third party. Reversible by
   search-and-replace until the first tag.
4. **License**: MIT for every package including the Univeros module, matching `polaris-core` and the framework.
   Reversible per package until the first tag.
