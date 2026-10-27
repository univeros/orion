# Guides

Task-first guides to using Orion in a PHP application: from a first search on the in-memory engine to
zero-downtime reindexing, multi-tenancy and production operations. Each guide shows working code and links to the
specification for the reasoning behind it.

## Reading order

Start here, in order:

1. [Getting started](getting-started.md): install, wire `Orion::create()`, create an index, write documents and run a
   first search, on the in-memory engine and then a real one.
2. [Index schemas](index-schemas.md): field types and flags, languages, the org key, engine-specific settings, and
   schemas as `search:` manifests.
3. [Indexing](indexing.md): the transactional outbox, updates and deletes, the drain, dead letters and projectors.
4. [Searching](searching.md): clauses, filters, facets, sorting, pagination, highlighting, vector and hybrid search,
   `compile()` and the native escape hatch.
5. [Index lifecycle](index-lifecycle.md): plan and apply, reindex with zero downtime, prune, drift, and signed
   receipts you can verify.

Then, as you need them:

- [Engines](engines.md): connecting Elasticsearch, OpenSearch, Meilisearch and Solr, and the capability matrix.
- [Multi-tenancy](multi-tenancy.md): one shared index for many tenants, with Polaris or your own resolver.
- [CLI reference](cli.md): every `bin/orion` command, with options, output and exit codes.
- [Testing](testing.md): testing your application with the in-memory engine, and the suites for adapter authors.
- [Frameworks](frameworks.md): the `search:` endpoints over PSR-15, and the Univeros module.

## Where else to look

- Each package's README, for what that package contains: start with
  [`orion-search/core`](../../packages/core/README.md).
- The [specification](../spec/orion-spec.md) and its [revision](../spec/revision.md), for why Orion works the way it
  does. Where a guide and the specification disagree, the guide describes the code as released.
- The [decisions log](../decisions.md), for each design choice and what was rejected.
