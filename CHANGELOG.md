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
