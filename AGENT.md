# AGENT.md — univeros/orion

Orientation for coding agents working in this repository. Read this before editing.

Orion is deterministic, replayable index operations for any full-text engine (Elasticsearch, Solr, OpenSearch,
Meilisearch), with a thin hybrid query layer, for any PHP application. It is built the way `polaris-core` is:
a framework-free core with ports and working defaults, host integrations as packages, one monorepo
split-published later. The Univeros module lives here too, as `packages/univeros`.

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
- **WP4 is next**: outbox, `Reindexer`, receipts, `orion-search/cli`. See `docs/spec/revision.md` §7.
- Local services for the conformance and determinism suites: `docker compose up -d`, then export `.env.testing`.

## The rules

1. **`composer qa` must pass** (phpcs, phpstan level 8, `check-imports`, phpunit) before any commit is
   proposed. Do not lower the level or skip tests to get green.
2. **Dependency isolation is mechanical.** `bin/check-imports` fails on `Altair\`, `Cycle\` or `Univeros\`
   outside `packages/univeros`; `Polaris\` outside `packages/polaris` and `packages/univeros`; an engine client
   outside its adapter package. Core depends on PSR interfaces, `symfony/uid`, `symfony/yaml` and `ext-sodium`
   only.
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
2. Work on `main` directly, or on a local `wpN` branch fast-forwarded into `main`. No pull requests.
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
packages/pdo, psr15, cli, polaris                       storage, HTTP, bin/orion, Polaris tenancy bridge (WP2, WP8, WP4, WP6)
packages/univeros                univeros/orion         Univeros\Orion\   the Univeros module (WP5)
examples/univeros                                        the demo host (WP5)
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
```

## Namespaces you will see and must not confuse

- `Orion\` — the agnostic core and its packages (this repository).
- `Univeros\Orion\` — the Univeros module, `packages/univeros` only.
- `Altair\*` — the Univeros framework, `packages/univeros` only.
- `Polaris\*` — `polaris/core`, `packages/polaris` and `packages/univeros` only.
