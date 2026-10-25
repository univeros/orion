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
| `orion-search/testing` | `Orion\Testing\` | In-memory storage, conformance test cases, fault injection. |
| `univeros/orion` | `Univeros\Orion\` | The Univeros module: one line in `config/modules.php`. |

More packages (PDO storage, PSR-15 endpoint, `bin/orion`, the Polaris tenancy bridge, one package per engine)
are added as their work package starts; see [`docs/spec/revision.md`](docs/spec/revision.md) §1 and §7.

## Status

Work package 0 (repository and QA) is done. Work package 1 (core ASTs, storage port, `Orion::create()`) is next.
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

## License

MIT, © 2026 2am.tech.
