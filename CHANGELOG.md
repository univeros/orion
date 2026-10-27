# Changelog

All notable changes to Orion (the `orion-search/*` packages and `univeros/orion`) are documented in this
file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres
to [Semantic Versioning](https://semver.org/). Every package shares one version line.

## [Unreleased]

### Fixed
- `bin/orion --version` printed `0.1.0` in every release; it prints the package version, `1.0.2`.

## [1.0.1] - 2026-10-27

Documentation only: no code or test changed.

### Added
- `docs/guides/`: task-first user guides with working examples, checked against the code at this version: getting
  started, index schemas, indexing, searching, index lifecycle, engines (with the capability matrix as the adapters
  report it), multi-tenancy, the CLI reference, testing and frameworks, with an overview giving the reading order.
- `packages/cli/README.md` and `packages/solr/README.md`.

### Changed
- `docs/README.md` is the entry point to the whole documentation: guides, packages, specification, decisions.
- The package READMEs say how to install each package and link to the guide that covers it. The core README lists
  what the graph provides and no longer claims health checks (they are the Univeros module's doctor checks) or a
  default for every port (storage, engines and tenancy have none); the testing README lists every helper and suite.
- The root README links the guides and lists `orion-search/psr15` in its Packages table, and its example passes the
  projected document's id and fields to `Outbox::index()`, which takes an array, not a `Document`; the Univeros
  module README's outbox example is corrected the same way and its configuration table gains
  `ORION_ENDPOINT_PREFIX`.
- `AGENT.md`: work lands on `main` through pull requests.

## [1.0.0] - 2026-10-27

The first release: every work package of the specification (`docs/spec/revision.md` §7), proven by the engine
conformance, tenancy isolation, determinism, fault-injection and query-equivalence suites on Elasticsearch 9.5 and
8.19, SolrCloud 10 and 9.11, OpenSearch 3.9 and 2.19, and Meilisearch 1.56 and 1.13, and by the Univeros demo
walkthrough, in CI.

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

- WP9: the agent surface in core (`Orion\Tools`: the `Tool` contract, `Tools::for()`, `ListIndices`, `DescribeIndex`,
  `Capabilities`, `CompileQuery`, `Search`, `Plan`, and the gated `Apply` and `Reindex`, which preview until `confirm`;
  `QuerySchema`, the JSON schema of a query's declarative form); `Searcher::compile()` and `Query\Compilation` (the
  request `search()` would send, bound and scoped, with the two legs and the fusion when core fuses, never sent);
  the `Adapter\QueryCompiling` port, implemented by the in-memory, Elasticsearch and Solr engines and checked by the
  conformance case; `ResultSet::toArray()`, `Hit::toArray()`, `FusionTrace::toArray()`; in `univeros/orion`, the
  `orion__*` MCP tools (`Mcp\OrionTool` and one adapter per core tool with its `#[McpTool]` attribute and schema
  file, `Mcp\McpTools`) registered on the framework's `ToolRegistry` so `bin/altair mcp:serve` offers them, a
  confirmed `apply` or `reindex` needing `--allow-writes`; the tools in the agent manifest section; `FaultyEngine::claim()`;
  the demo walkthrough calling `orion__compile_query` over stdio.
- WP8: `orion-search/psr15`, the `search:` endpoints as a PSR-15 middleware (`SearchMiddleware`, `SearchHandler`,
  `SearchRequest`, `Envelope`, RFC 9457 `Problem`s, the `Authorizer` port, `RequestTenant`); the manifest's `search`
  (`Manifest\Endpoint`) and `source` (`Manifest\Source`) keys and the `Projection\Projector` contract;
  `Manifest::endpoints()`; a `match` over several weighted fields (`Query::match()` with a list or weights by field,
  `Clause::$fields`, `weighted()`, `fieldNames()`; `fields` and `boost` in the declarative form); `Query\Binder`, the
  query bound to the registered schema (fields, flags, value types, query-time boosts) by the searcher; core
  reciprocal-rank fusion of hybrid queries in the `Searcher` (`lexicalLeg()`, `vectorLeg()`, `withClauses()`,
  `Searcher::FUSION_WINDOW`); `Page::WINDOW`, `Page::depth()` and `UnsupportedClause::deepPaging()`;
  `IndexSchema::fieldAt()`; in `univeros/orion`, `Http\SearchEndpoints`, `Http\GateAuthorizer`, one route per endpoint
  and the endpoints in the agent manifest section; in `orion-search/testing`, the endpoint harness and fixtures
  (`Endpoint\EndpointFixtureTestCase`, `Harness`, `Fixture`, `fixtures/manifests`, `fixtures/endpoints`) and the
  property-based query equivalence case (`Equivalence\QueryEquivalenceTestCase`, `QueryGenerator`, `Seeded`);
  `FaultyEngine` kills searches too; the demo serves its search from the manifest's endpoint. Endpoint responses
  carry `Cache-Control: private, no-store`; a `q` is at most 64 words and a sort names each field once.
- WP7: `orion-search/solr`, the SolrCloud 9.x and 10.x adapter (`SolrEngine`, `QueryCompiler`, `NativeScope`, `Schema`,
  `Configset` with Orion's configset); `ORION_ENGINE=solr` with `ORION_SOLR_URL` in `univeros/orion`; Solr 10 and 9.11
  in `compose.yaml` and CI.
- WP10: `orion-search/opensearch`, the engine adapter for OpenSearch 2.19 and 3.x on `opensearch-project/opensearch-php`
  (`OpenSearchEngine`, `OpenSearchEngine::connect()` over Guzzle, `QueryCompiler`, `Mapping`, `BulkResponse`): strict
  mappings, externally versioned writes and deletes, the neutral query compiled to the Query DSL, vectors as
  `knn_vector` fields on the Lucene engine queried through the k-NN plugin's `knn` query with the filters applied
  while the neighbours are gathered, hybrid fusion left to core; a vector joining an index created without one is
  `RequiresReindex`. In `univeros/orion`, `ORION_ENGINE=opensearch` with `ORION_OPENSEARCH_URL`. The endpoint
  fixtures replay on both OpenSearch lines; `compose.yaml`, `.env.testing` and CI carry the two services.
- WP11: `orion-search/meilisearch`, the engine adapter for Meilisearch 1.13 and later on `meilisearch/meilisearch-php`
  over Guzzle (`MeilisearchEngine`, `MeilisearchEngine::connect()`, `QueryCompiler`, `Settings`, `Documents`): the
  neutral schema as index settings plus a declaration document Orion keeps in the index, documents validated against
  it before they are sent (an undeclared field or a wrong type fails alone), every write a task that
  `BulkResult::await()` waits for, versions kept by Orion (a stale write or delete found by reading the stored
  version, a delete leaving a tombstone every search excludes), aliases emulated by core, lexical clauses as filters
  on hex-encoded shadow attributes with the portable semantics (never `q`, which stays for the escape hatch), vectors
  as user-provided embeddings ranked by cosine similarity, facets counted within the k nearest, highlights as the
  conformance case defines them, hybrid fusion left to core. In `univeros/orion`, `ORION_ENGINE=meilisearch` with
  `ORION_MEILISEARCH_URL` and an optional `ORION_MEILISEARCH_KEY`. The endpoint fixtures replay on both lines;
  `compose.yaml`, `.env.testing` and CI carry the two services.

### Changed
- WP11: `QueryEquivalenceTestCase` lets a fused query's hit count differ from the oracle's by the slack, because an
  approximate neighbour may coincide with a lexical match where the exact one did not; the slack is a protected
  constant of the leaf.
- WP10: the Elasticsearch scoped escape hatch ANDs the scope into every `knn` query at any depth of the native body
  (the `knn` query clause of 8.12 and later, in `post_filter`, `rescore`, a highlight query or another entry's filter),
  not only the top-level entries, each k-NN filter written as one `bool` filter; the body is taken as the JSON it
  would be sent as. Unscoped, whether a tenant's document came back told where the foreign vectors lay.
- WP9: `Orion\Psr15\Envelope` composes the response over `ResultSet::toArray()` (same bytes). The searcher's
  `search()` and `compile()` share the binding, window, scope and leg logic, so what `compile_query` shows is what
  `search()` sends. The engine conformance case requires every engine to be a `QueryCompiling`. The root's
  `autoload-dev` maps `Altair\Mcp\` to verbatim copies of the univeros/mcp v2.6.0 contracts under
  `packages/univeros/tests/Stubs` (univeros/mcp cannot be installed beside the Elasticsearch 9 client); a host with the
  real package never reads them.
- WP8: no engine returns vector fields in a hit's source (the in-memory engine strips them, Solr leaves them out of
  its stored source, the Elasticsearch mapping excludes them from `_source` so 8.19 answers as 9 does); Solr compiles the neutral `_score` sort to its `score` field; the
  conformance case pins both and a `match` over several fields. The in-memory engine no longer applies the schema's
  boost itself: the searcher's binding does, for every engine. A query naming an undeclared field, a field not flagged
  for the use, a value of the wrong type, or a page past 10 000 results is refused by the searcher before any engine
  sees it. The isolation case gains hybrid queries.
- WP7: the engine conformance case also requires that a deleted document is found by no query, that missing sort
  values come last either way, that only a matching field is highlighted, that `match` on a keyword finds the whole
  value, that term filters read values as their fields declare, and that `exists` works on an object.
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
