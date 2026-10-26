# Changelog

All notable changes to Orion (the `orion-search/*` packages and `univeros/orion`) are documented in this
file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres
to [Semantic Versioning](https://semver.org/). Every package shares one version line.

## [Unreleased]

### Added
- WP0: the monorepo, root QA (`composer qa`), `bin/check-imports` with tests, the `orion-search/core` and
  `orion-search/testing` skeletons, the specification and the decisions log, CI.
- WP1: the query AST (`Query::on()`, lexical clauses, filters, k-NN vector, fusion, sort, facets, highlight,
  pagination) with its declarative form; `ResultSet`, `Hit`, `FusionTrace` and deterministic reciprocal rank
  fusion in core; the `IndexSchema` AST with `orgKey()` and per-engine `native()` settings, `LiveSchema` and
  `MigrationPlan` with total, fail-closed change classification; `Document`, `BulkResult`, `WriteOutcome`; the
  `SearchAdapter`, `SchemaAdapter`, `WriteAdapter` contracts, `Capability` and `UnsupportedClause`; the `Storage`
  port with Orion's tables as data; `InMemoryStorage` and `StorageConformanceTestCase` (same-connection test
  included) in `orion-search/testing`; `Orion::create(new Config(...))` with explicit tenancy.
- WP2: adapter contracts with version-stamped deletes (`Tombstone`), `SchemaAdapter::drop()` and a scopable
  `SearchAdapter::raw()`; `EngineConformanceTestCase` and the dev `InMemory` engine; `orion-search/pdo`, storage on
  the host's PDO connection that joins the host's transaction, with the table installer (SQLite, PostgreSQL, MySQL);
  `orion-search/elasticsearch` for Elasticsearch 8.19 and 9.x; `compose.yaml` and `.env.testing` for the local
  service-backed suites.
- WP3: the schema lifecycle. `MigrationPlan::between()` diffs the org key and per-engine native settings against
  the registered schema, failing closed; `IndexSchema::hash()`; the index registry (`orion_index` per versioned
  physical index, `orion_alias` per alias, `IndexRegistration`, `IndexRegistry`); `SchemaAdapter::alias()` with the
  Elasticsearch implementation and an alias conformance test; `AliasManager`, `IndexNamer`, `Planner` (plan, create
  version 1 behind the alias, additive apply in place, `reindex_required` for breaking plans and refused additive
  ones), `Operation` and `Outcome`; `Graph::registry()`, `aliases()`, `planner()`; `LifecycleDeterminismTestCase` and
  `FixedClock` in `orion-search/testing`.

- WP4: `Storage::increment()` and `Storage\Counters`, so outbox offsets per index are dense and committed in order;
  the compacted transactional outbox (`Outbox::index()`, `delete()`, `Entry`); the drain (`Drain`, `DrainResult`,
  `Delivery`) with dead letters and retry; the `Dispatcher` (`SyncDispatcher` by default) and `Metrics` ports;
  receipts (`Receipt`, `ReceiptSigner`, `SodiumSigner`, `ReceiptStore`, `Verifier`), issued by the planner and the
  reindexer; the `Reindexer` (replay, leased lock in `orion_reindex`, resume, `abort()`, `prunable()`, `prune()`);
  `Graph::outbox()`, `drain()`, `deadLetters()`, `dispatcher()`, `metrics()`, `receipts()`, `verifier()`,
  `reindexer()`; `orion-search/cli` with `bin/orion`; `FaultyEngine`, `FaultyStorage`, `SettableClock` and
  `FaultInjectionTestCase` in `orion-search/testing`; the determinism harness covers reindex, prune and receipts;
  `ReceiptVerifier` and `SodiumVerifier` (verification from public keys), `orion verify --head` for an anchored head;
  `Storage\DuplicateKey`; `AliasingEngine` in `orion-search/testing`.

- WP5: `univeros/orion`, the Univeros module (`Module`, `Bootstrap\OrionConfig`, `Database\CycleExecutor`,
  `CycleStorage`, `CycleInstaller`, the `InstallOrion` migration, `Console\Commands`, seven `Doctor` checks,
  `Messaging\BusDispatcher` and `DrainIndexHandler`, `Observability\MeterMetrics`, `AgentSpec\ManifestSection`);
  `Orion\Pdo\SqlStorage`, `Executor` and `PdoExecutor`; `Orion\Manifest\Loader`, `Manifest`, `Declaration` and
  `Graph::manifest()`; `SchemaAdapter::resolve()`; `ElasticsearchEngine::connect()`; the harnesses' `createStorage()`
  hook; `examples/univeros` with `bin/setup` and `bin/walkthrough.sh`, and a CI job running it.

- WP6: tenancy on a shared index. `Orion\Query\Searcher` (`Graph::searcher()`), the one way Orion queries an engine,
  with the tenant's filter injected beneath every query and the escape hatch; `Tenancy\ScopeInjector`, `IndexScope`
  and `NoTenant`; `TenantScope::qualify()` and `unqualify()`; `TenancyIsolationTestCase` and `SettableResolver` in
  `orion-search/testing`, run on the in-memory engine and Elasticsearch 9 and 8.19; `orion-search/polaris` with
  `ActiveOrganization`; in `univeros/orion`, tenancy from univeros/polaris through `Http\RequestToken`, the bound
  `Searcher`, and `ORION_ENGINE=elasticsearch` with `ORION_ELASTICSEARCH_URL`.

### Changed
- WP6: under a tenant resolver, `Outbox::index()` and `delete()` record as the current tenant (qualified id, org key
  stamped) and `Planner::apply()` and `Reindexer::reindex()` refuse a schema whose org key does not fit the tenancy or
  that changes the org key of an index holding documents; `Outbox`, `Planner` and `Reindexer` take a `ScopeInjector`.
  Elasticsearch's scoped escape hatch refuses document references and `wrapper`, and native mappings cannot touch the
  org key. `bin/check-imports` matches root namespaces only, reads every source under `packages/` and `examples/`,
  matches `use` statements in any case and sees group use.
- WP5: `PdoStorage` delegates to `SqlStorage`; on MySQL, `update()` counts matches with a locking read, so a
  compare-and-set another transaction won no longer counts as a match. The CLI reads schemas and engines from the
  `search:` manifest when `--schema` is not given, and `--schema` accepts a manifest file; `Cli\Context` takes a lazy
  resolver and `Cli\Application::commands()` lists the commands for a host's console.
- WP4: `orion_outbox` is keyed on (`index_name`, `outbox_offset`) with one row per document; `orion_receipt` is keyed
  on `sequence` and gains `engine`; `orion_counter`, `orion_dead_letter` and `orion_reindex` are new. `Planner` issues
  receipts, refuses an apply while a reindex is pending, and reports a query-time-only change as `applied`.
  `Lifecycle\Operation` gains `reindexed` and `dropped`. `bin/check-imports` confines the Symfony console to the CLI
  and host packages.
- WP3: `orion_index` and `orion_alias` columns (provisional until now) take their final shape; `Change::$before` and
  `$after` may hold a declared setting value as well as a field.
