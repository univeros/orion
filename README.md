# Orion

> **Deterministic, replayable index operations for any full-text engine, with a thin honest hybrid query layer, for any PHP application.**
> Framework-free core, dumb engine adapters, lifecycle you can prove.

Orion is not a search engine. It sits on Elasticsearch, Solr, OpenSearch or Meilisearch and owns the part
everyone hand-rolls and gets wrong: the index lifecycle. Schema as code, drift detection, deterministic
migration plans, zero-downtime reindex by replaying a transactional outbox, versioned indices behind an alias,
and a signed receipt for every operation. The query layer covers the common case as a typed contract, ships
reciprocal-rank hybrid retrieval, and hands you the engine's native query for the rest.

Two honest sentences: the lifecycle is deterministic and provably so; ranking is not, and Orion never claims it is.

## Packages

| Package | Namespace | What it is |
| --- | --- | --- |
| `orion-search/core` | `Orion\` | The ASTs, adapter contracts, lifecycle, outbox, receipts, tenancy seam. PSR interfaces only. |
| `orion-search/testing` | `Orion\Testing\` | In-memory storage, the storage and engine conformance test cases. |
| `orion-search/pdo` | `Orion\Pdo\` | Storage on your PDO connection (PostgreSQL, MySQL, SQLite), table installer. |
| `orion-search/elasticsearch` | `Orion\Elasticsearch\` | Engine adapter for Elasticsearch 8.19 and 9.x. |

More packages (PSR-15 endpoint, `bin/orion`, the Polaris tenancy bridge, the Univeros module, Solr, OpenSearch,
Meilisearch) are added as their work package starts; see [`docs/spec/revision.md`](docs/spec/revision.md) §1 and §7.

## Status

Work packages 0 to 2 are done: the core ASTs and contracts, storage on PDO, the engine conformance suite, the dev
in-memory engine and the Elasticsearch adapter. Work package 3 (schema lifecycle) is next; progress is tracked as
epics in the repository's issues.
Nothing is published yet. This repository is the monorepo; each package will be split to a read-only
repository for Composer when the first tag is cut.

## Documentation

- [`docs/spec/orion-spec.md`](docs/spec/orion-spec.md) — the product specification.
- [`docs/spec/revision.md`](docs/spec/revision.md) — the framework-agnostic layout and the work packages.
- [`docs/decisions.md`](docs/decisions.md) — the decisions log.
- [`AGENT.md`](AGENT.md) — orientation for coding agents.

## Development

```sh
composer install
composer qa        # phpcs + phpstan + check-imports + phpunit
```

Suites that need a database or an engine skip unless their connection is set. To run them all:

```sh
docker compose up -d                              # PostgreSQL, MySQL, Elasticsearch 9 and 8
export $(grep -v '^#' .env.testing | xargs)
composer qa
```

CI runs every service-backed suite and fails on any skip.

## License

MIT, © 2026 2am.tech.
