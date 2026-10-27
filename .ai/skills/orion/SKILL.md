---
name: orion
description: Work on or integrate Orion, the deterministic index-lifecycle and hybrid search layer for PHP (orion-search/* packages, univeros/orion module). Use when designing or implementing an engine adapter, the schema planner, the outbox replay reindexer, receipts, tenancy, the search: manifest, or when wiring Orion into a host application.
---

# Orion: index lifecycle you can prove, search you can introspect

Read `AGENT.md`, then `docs/spec/revision.md` (layout, ports, work packages), then the section of
`docs/spec/orion-spec.md` your task touches. Check `docs/decisions.md` before proposing a design.

## The shape in one paragraph

A framework-free core (`Orion\`) owns the query and schema ASTs, three thin adapter contracts
(`SearchAdapter`, `SchemaAdapter`, `WriteAdapter`) plus a `Capability` enum, and all orchestration: planner with
total change classification, versioned indices behind an alias, zero-downtime reindex by replaying a
transactional outbox with version-stamped idempotent writes, Ed25519-signed hash-chained receipts, and a tenant
scope injected beneath the AST. `Orion::create(new Config(...))` builds it without a container; every port has a
default except storage, engines and tenancy, which are explicit. Hosts bind ports: the Univeros module
(`packages/univeros`) over Cycle's connection, Messenger, the Polaris token and the Doctor.

## Invariants to keep

- Outbox rows commit on the caller's connection, in the caller's transaction.
- Tenancy is chosen explicitly; `TenantScope` applies to every compiled query, escape hatch included. Query through `Graph::searcher()`, never an adapter directly: it injects the tenant filter, and `NoUnscopedQueryPathTest` fails on any new public path that takes a `Query` outside it. Under a resolver every index declares an org key.
- Unknown schema change is breaking; unsupported clause is `UnsupportedClause`; missing capability is routed
  around or refused, never silently wrong.
- Adapters implement primitives only. Orchestration in an adapter is a core bug. The `Searcher` binds every query to
  the registered schema (`Binder`) and fuses hybrid queries in core; adapters compile what they are given.
- The `search:` manifest's `search` block is the endpoint: `orion-search/psr15` serves it, the module mounts it behind
  `RequestToken`; a request may only name what the endpoint declares, the endpoint only what the schema flags.
- The agent surface is `Orion\Tools` (framework-free; `compile_query` shows what `search()` would send, through the
  same binding and scope, and sends nothing). A host adapts those tools, keeping their names, descriptions and
  schemas; no tool constructs a schema, and `apply`/`reindex` do nothing without `confirm`.
- Lifecycle is deterministic; ranking is not. Do not write code or docs that claim otherwise.

## Working

- `composer qa` before any commit; `bin/check-imports` decides where code may live.
- Tests first. An engine adapter is done when it passes `Orion\Testing\EngineConformanceTestCase`; a storage
  adapter when it passes `StorageConformanceTestCase`, same-connection test included.
- Decisions to `docs/decisions.md`, append only.
