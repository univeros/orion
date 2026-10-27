# AGENT.md — univeros/orion

Orientation for coding agents working in this repository. Read this before editing.

Orion is deterministic, replayable index operations for any full-text engine (Elasticsearch, Solr, OpenSearch,
Meilisearch), with a thin hybrid query layer, for any PHP application. It is built the way `polaris-core` is:
a framework-free core with ports and working defaults, host integrations as packages, one monorepo
split-published to `univeros/orion.<package>` and Packagist by `.github/workflows/split.yml`. The Univeros module lives here too, as `packages/univeros`.

## Where things are decided

- `docs/spec/orion-spec.md` — the product specification (v1). What Orion is and is not.
- `docs/spec/revision.md` — the framework-agnostic layout (v2 delta): packages, ports, the same-connection
  invariant, the Univeros module, the work packages (§7). **Where it disagrees with v1, it wins.**
- `docs/decisions.md` — append-only log. Read it before proposing something it already settled.

## Status

- **WP0 complete** (2026-10-25): repository, root QA, `bin/check-imports` with tests, `packages/core` and
  `packages/testing` skeletons, docs, CI.
- **WP1 complete** (2026-10-25): query and schema ASTs with their declarative forms, core RRF, total schema
  change classification, write types, adapter contracts and `Capability`, the `Storage` port with
  `InMemoryStorage` and `StorageConformanceTestCase` (same-connection test included), `Orion::create()`.
- **WP2 complete** (2026-10-25): versioned deletes, `drop()` and a scopable `raw()` in the contracts;
  `EngineConformanceTestCase` and the dev `InMemory` engine; `orion-search/pdo` (host-transaction aware);
  `orion-search/elasticsearch` (8.19 and 9.x).
- **WP3 complete** (2026-10-26): `Planner` (plan and apply for one engine), the org key and native settings diffed
  against the registration, versioned physical indices behind an alias (`IndexNamer`, `AliasManager`,
  `SchemaAdapter::alias()`), the index registry (`orion_index`, `orion_alias`, `IndexRegistration`), `Outcome` as the
  receipt seam, and `LifecycleDeterminismTestCase`, green on the in-memory engine and Elasticsearch 9 and 8.19.
- **WP4 complete** (2026-10-26): `Storage::increment()` and the counter table (outbox offsets dense and committed in
  order), the compacted transactional outbox, the drain with dead letters, the `Dispatcher` and `Metrics` ports,
  signed hash-chained receipts (`ReceiptSigner`, `SodiumSigner`, `ReceiptStore`, `Verifier`), the `Reindexer` (replay,
  leased lock, resume, abort, prune), `orion-search/cli` (`bin/orion`), and `FaultInjectionTestCase`, green on the
  in-memory engine and Elasticsearch 9 and 8.19.
- **WP5 complete** (2026-10-26): `SqlStorage` over an `Executor` port (PDO and Cycle), `univeros/orion` (storage on the
  host's Cycle connection, migration, `orion:*` commands, the seven doctor checks with `--fix`, bus dispatcher, Meter
  metrics, agent manifest section), the `search:` manifest's index declarations in core, `SchemaAdapter::resolve()`,
  the harnesses on Cycle storage, and `examples/univeros` with a walkthrough.
- **WP6 complete** (2026-10-26): tenancy on a shared index. `Graph::searcher()` injects the tenant's filter beneath
  every query and the escape hatch; outbox writes are tenant-qualified and stamped with the org key; an index's org
  key must fit the tenancy; `TenancyIsolationTestCase` gates every engine; `orion-search/polaris` resolves the tenant
  from the Polaris token, and the module wires it when univeros/polaris is configured.
- **WP7 complete** (2026-10-26): `orion-search/solr` for SolrCloud 9.x and 10.x, passing engine conformance, tenancy
  isolation, determinism and fault injection on both; the neutral schema needed no Lucene-shaped change.
- **WP8 complete** (2026-10-26): the query bound to the registered schema (`Binder`: fields, flags, value types,
  query-time boosts) and fused in core (reciprocal rank across the scoped legs) by the `Searcher`; a `match` over
  several weighted fields; the offset window refused as `UnsupportedClause`; the manifest's `search` and `source`
  keys; `orion-search/psr15` serving the declared endpoints with RFC 9457 problems; the module serving them behind
  `RequestToken` with the Polaris `Gate`; the endpoint fixtures through both harnesses and on every engine; the
  property-based query equivalence suite green on Elasticsearch and Solr.
- **WP9 complete** (2026-10-27): the agent surface in core (`Orion\Tools`: `compile_query`, `search`, `list_indices`,
  `describe_index`, `capabilities`, `plan`; `apply` and `reindex` previewing until `confirm`), `Searcher::compile()`
  over the engines' `QueryCompiling` port (the request `search()` would send, bound and scoped, never sent), and the
  module's `orion__*` adapters on the framework's MCP server (`mcp:serve`, `--allow-writes` for a confirmed mutation).
- **WP10 complete** (2026-10-27): `orion-search/opensearch` for OpenSearch 2.19 and 3.x on the official client, passing
  engine conformance, tenancy isolation, determinism, fault injection, query equivalence and the endpoint fixtures on
  both; vectors through the bundled k-NN plugin, hybrid fusion left to core; `ORION_ENGINE=opensearch` in the module.
- **WP11 complete** (2026-10-27): `orion-search/meilisearch` for Meilisearch 1.13 and later, passing engine conformance,
  tenancy isolation, determinism, fault injection, query equivalence and the endpoint fixtures on 1.56 and 1.13: the
  schema as settings plus a declaration document, documents validated before they are sent, versions kept by Orion
  with tombstones, writes as awaited tasks, aliases emulated by core, lexical clauses as filters on shadow attributes,
  vectors as user-provided embeddings, hybrid fusion left to core; nothing leaked upward into the ports.
- **1.0.0** (2026-10-27): every work package done, CI green on every engine since the repository went public, the
  version line at `^1.0` / `1.x-dev`.
- **1.0.1** (2026-10-27): the user guides (`docs/guides`); every package split to its read-only repository and
  published on Packagist. Every push to `main` and every `v*` tag updates the splits and pings Packagist.
- Local services for the conformance and determinism suites: `docker compose up -d`, then export `.env.testing`.

## The rules

1. **`composer qa` must pass** (phpcs, phpstan level 8, `check-imports`, phpunit) before any commit is
   proposed. Do not lower the level or skip tests to get green.
2. **Dependency isolation is mechanical.** `bin/check-imports` fails on `Altair\`, `Cycle\` or `Univeros\`
   outside `packages/univeros`; `Polaris\` outside `packages/polaris` and `packages/univeros`; an engine client
   outside its adapter package; the Symfony console outside `packages/cli` and the host packages. Core depends on
   PSR interfaces, `symfony/uid`, `symfony/yaml` and `ext-sodium` only.
3. **Dumb adapters, smart neutral core.** An engine adapter implements `SearchAdapter`, `SchemaAdapter`,
   `WriteAdapter` and reports `Capability`; diffing, reindex, alias swap, replay and fusion live once in core.
   If an adapter needs orchestration logic, the core is missing something.
4. **Determinism on the lifecycle, never on ranking.** Plan, apply, reindex and swap are pure and reproducible;
   result order is not, and nothing may claim otherwise.
5. **The outbox commits with the entity write, on the same connection.** `Outbox::record()` runs inside the
   caller's transaction on the host's `Storage`. Never open a second connection for it.
6. **Tenancy is explicit.** `Tenancy::single()` or a `TenantResolver`; `TenantScope` is injected beneath the
   AST into every compiled query, escape hatch included. No code path may skip it.
7. **Fail closed.** An unrecognised schema change is breaking; an unsupported clause is a typed
   `UnsupportedClause`; a capability an engine lacks is routed around or refused, never silently wrong.
8. **Proof is the product.** Every engine adapter extends and passes the conformance test case before it
   ships; the determinism harness and fault injection gate the lifecycle WPs.
9. **House style.** `declare(strict_types=1)`, `final readonly` value objects, immutable updates (return a
   copy), small files, tests first, UUID v7 ids, no hard-coded values.

## How to work a work package

1. Read `docs/spec/revision.md` §7 for the WP's scope and acceptance criteria.
2. Work on a branch (`wpN`, `docs/…`, `fix/…`) and land it on `main` through a pull request, so CI runs before it
   merges (from 2026-10-27; earlier work landed on `main` directly).
3. Write the failing test, then the smallest change that meets the criteria; run `composer qa` continuously.
4. Record decisions in `docs/decisions.md`, append only.
5. Commit in coherent steps, conventional format (`feat:`, `fix:`, `refactor:`, `docs:`, `test:`, `chore:`),
   authored by the owner only: no `Co-Authored-By` or any other attribution trailer, ever.
6. Close the WP's epic with a comment listing each acceptance criterion and how it was verified.

## Layout

```
packages/core/{src,tests}        orion-search/core      Orion\            the framework-free core (revision.md §6)
packages/testing/src             orion-search/testing   Orion\Testing\    in-memory storage, conformance cases, fakes
packages/<engine>                orion-search/<engine>  one engine adapter each, own client dependency (from WP2)
packages/pdo, psr15, cli, polaris                       storage, the search: endpoints over PSR-15, bin/orion, Polaris tenancy bridge
packages/univeros                univeros/orion         Univeros\Orion\   the Univeros module
examples/univeros                                        the demo host: bin/setup, bin/walkthrough.sh (needs Elasticsearch)
docs/                            spec, revision, decisions
.ai/skills/orion/SKILL.md        the agent skill
bin/check-imports                the dependency-isolation gate
```

## Commands

```
composer qa              # cs + stan + imports + test (service suites skip unless .env.testing is exported)
composer test            # phpunit
composer stan
composer cs-fix
bin/check-imports        # alone
packages/cli/bin/orion --bootstrap=<file returning Orion or Config> list
```

## Namespaces you will see and must not confuse

- `Orion\` — the agnostic core and its packages (this repository).
- `Univeros\Orion\` — the Univeros module, `packages/univeros` only.
- `Altair\*` — the Univeros framework, `packages/univeros` only.
- `Polaris\*` — `polaris/core`, `packages/polaris` and `packages/univeros` only.
