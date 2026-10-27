# Orion

[![CI](https://github.com/univeros/orion/actions/workflows/ci.yml/badge.svg)](https://github.com/univeros/orion/actions/workflows/ci.yml)

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
| `orion-search/testing` | `Orion\Testing\` | In-memory storage, the storage and engine conformance test cases, the lifecycle determinism and fault-injection harnesses. |
| `orion-search/pdo` | `Orion\Pdo\` | Storage on your PDO connection (PostgreSQL, MySQL, SQLite), table installer. |
| `orion-search/elasticsearch` | `Orion\Elasticsearch\` | Engine adapter for Elasticsearch 8.19 and 9.x. |
| `orion-search/cli` | `Orion\Cli\` | `bin/orion`: plan, apply, reindex, prune, drift, status, flush, verify, the outbox drain. YAML or deterministic JSON. |
| `orion-search/solr` | `Orion\Solr\` | Engine adapter for SolrCloud 9.x and 10.x. |
| `orion-search/opensearch` | `Orion\OpenSearch\` | Engine adapter for OpenSearch 2.19 and 3.x. |
| `orion-search/meilisearch` | `Orion\Meilisearch\` | Engine adapter for Meilisearch 1.13 and later. |
| `orion-search/psr15` | `Orion\Psr15\` | The `search:` endpoints a manifest declares, served as PSR-15 middleware, with RFC 9457 problems. |
| `orion-search/polaris` | `Orion\Polaris\` | Tenancy from Polaris: the tenant is the active organization on the verified token. |
| `univeros/orion` | `Univeros\Orion\` | The Univeros module: one line in `config/modules.php`; storage on the host's Cycle connection, migration, `orion:*` commands, doctor checks. |

Host adapters for other frameworks are added as packages when asked for; see [`docs/spec/revision.md`](docs/spec/revision.md) §1 and §7.

## How it fits together

```php
$orion = Orion::create(new Config(
    storage: new PdoStorage($pdo),                       // your connection: the outbox commits with your entity
    engines: new EngineRegistry(elasticsearch: $adapter),
    tenancy: Tenancy::single(),
    receiptSigner: SodiumSigner::fromBase64($secretKey, 'prod-1'),
));
$graph = $orion->graph();

$graph->planner('elasticsearch')->apply($schema);      // creates products_v1 behind the alias, signed receipt

$pdo->beginTransaction();                              // your write and its outbox entry, one transaction
saveProduct($pdo, $product);
$document = $projector->project($product);              // an Orion\Write\Document
$graph->outbox()->index(IndexName::of('products'), $document->id, $document->fields);
$pdo->commit();
$graph->dispatcher()->dispatch(IndexName::of('products'));   // drain now, or let `orion outbox:drain` do it

$results = $graph->searcher()->search(Query::on('products')->match('title', 'trail shoe'));

$graph->reindexer('elasticsearch')->reindex($changedSchema); // replay into products_v2, swap, signed receipt
$graph->verifier()->verify();                          // the receipt chain, hashes, signatures, every alias attested
```

Declare indices in `api/<name>/search.yaml` (`index`, `engine`, `schema`, `org_key`): `orion apply`, `orion drift`
and the doctor read them. Record into the outbox as the last statement of your transaction: writers to one index queue on its offset counter
until they commit, which is what keeps offsets in commit order. An auditor verifies with public keys only:
`receiptVerifier: SodiumVerifier::fromBase64(['prod-1' => $publicKey])`.

Tenancy is a choice with no default. `Tenancy::single()` is one tenant. `Tenancy::resolvedBy($resolver)` shares each
index between tenants: every index then declares an `org_key`, the searcher filters on the current tenant beneath
every query (the escape hatch included), and outbox writes are recorded under the tenant's ids with the org key
stamped, so no query returns, overwrites or deletes another tenant's document. `orion-search/polaris` resolves the
tenant from the Polaris token's active organization.

The same operations from a shell, for people and agents alike:

```sh
bin/orion apply products --schema=products.yaml --bootstrap=orion.php --json
bin/orion reindex products --schema=products.yaml --dry-run
bin/orion outbox:drain && bin/orion verify --json
```

## Status

Work packages 0 to 11 are done: the core ASTs and contracts, storage on PDO, the engine conformance suite, the dev
in-memory engine, the Elasticsearch, Solr, OpenSearch and Meilisearch adapters, the schema lifecycle (plan, apply, versioned indices behind an
alias, the index registry), the transactional outbox and its drain, zero-downtime reindex by replay, signed
hash-chained receipts, `bin/orion`, the determinism, fault-injection, tenancy-isolation and query-equivalence
harnesses, tenancy on a shared index with the tenant from the Polaris token, the `search:` manifest with its index and
endpoint declarations, the endpoints served over PSR-15 and by the Univeros module, the agent surface (`compile_query`
and the other core tools, as `orion__*` on the framework's MCP server), and a demo host with a walkthrough
(`examples/univeros`). Every work package of the specification is done and version 1.0.0 is tagged in this
monorepo, with CI green on every engine. Progress is tracked as epics in the repository's issues.
The per-package read-only repositories (`univeros/orion.<package>`) and their Packagist registration are the
next step; until then, install from a clone with path repositories, as `examples/univeros` does.

## Documentation

- [`docs/guides/`](docs/guides/README.md) — the user guides: start with
  [Getting started](docs/guides/getting-started.md).
- [`docs/README.md`](docs/README.md) — the documentation index: guides, packages, specification, decisions.
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
