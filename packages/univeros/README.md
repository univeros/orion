# univeros/orion

Orion as a Univeros module. One line in `config/modules.php`:

```php
return [
    new App\AppModule(),
    new Univeros\Orion\Module(),
];
```

What that line gives the application:

- **Orion on the application's own Cycle connection.** The outbox entry commits or rolls back with the entity in
  the same transaction, which is what makes reindex by replay lossless (docs/spec/revision.md §4).
- **The migration.** `bin/altair db:migrate` installs Orion's tables.
- **The console.** The `orion:*` commands: plan, apply, reindex, prune, drift, status, flush, verify, capabilities,
  outbox:drain, outbox:retry and receipts:keygen. The application's `bin/altair` adds them with
  `foreach (Univeros\Orion\Console\Commands::all($container) as $command) { $application->add($command); }`.
- **The doctor checks.** Seven checks join `bin/altair doctor`, with `--fix` where it is safe.
- **The bus.** With `univeros/messaging` configured, the drain runs on the bus worker after each commit.
- **The Meter.** With `univeros/observability`, Orion's counters and the outbox lag gauge go to the Meter.
- **The AI-readable manifest.** Its section in `bin/altair manifest:generate`.

Nothing is built until something resolves it, so `db:migrate` runs before Orion is configured.

## Configuration

Anything the container binds wins over the environment.

| Setting | Binding | Environment |
| --- | --- | --- |
| Storage | `Orion\Storage\Storage` | the Cycle connection `CycleOrmConfiguration` configures (`DB_*`) |
| Engines | `Orion\Registry\EngineRegistry`, for several engines or a client you build yourself | `ORION_ENGINE=elasticsearch` with `ORION_ELASTICSEARCH_URL` (comma-separated hosts; needs `orion-search/elasticsearch`), `ORION_ENGINE=opensearch` with `ORION_OPENSEARCH_URL` (a node; needs `orion-search/opensearch`), `ORION_ENGINE=solr` with `ORION_SOLR_URL` (a SolrCloud node; needs `orion-search/solr`), or `ORION_ENGINE=memory` (development only) |
| Tenancy | `Orion\Tenancy\Tenancy` | `ORION_TENANCY=single`; there is no default |
| Receipt signing | `Orion\Receipt\ReceiptSigner` | `ORION_RECEIPT_KID`, `ORION_RECEIPT_SECRET_KEY` (base64), `ORION_RECEIPT_PREVIOUS_KEYS` (`kid:base64,...`) |
| Receipt verification | `Orion\Receipt\ReceiptVerifier` | `ORION_RECEIPT_PUBLIC_KEYS` (`kid:base64,...`), so an auditor needs no secret |
| Manifests | | `ORION_MANIFESTS` (comma-separated directories; `api` by default) |
| Clock, logger, events, cache | the PSR interfaces | |
| Doctor lag threshold | | `ORION_DOCTOR_MAX_LAG` (1000 by default) |

`bin/altair orion:receipts:keygen --kid=prod-1` prints a key pair for the secret store.

## Indices

Declare each index in a `search:` manifest, `api/<name>/search.yaml`:

```yaml
index: products
engine: elasticsearch
schema:
  title: { type: text, language: en }
  sku: { type: keyword, filterable: true }
org_key: org_id   # optional
```

`bin/altair orion:apply products` builds it, and `orion:drift --check` reports when the declaration and the index
part ways.

## Doctor

| Check | Status when failing | `--fix` |
| --- | --- | --- |
| `orion_engine_reachable` | error | none; reports the connection setting |
| `orion_registry_current` | warn | builds declared indices; clears what a crashed create left, only when it holds no documents |
| `orion_schema_drift` | error when breaking, warn when additive | applies the additive changes; breaking drift reports `bin/altair orion:reindex <index>` |
| `orion_alias_resolves` | error | none; the agent action resumes the reindex |
| `orion_outbox_lag` | warn above `ORION_DOCTOR_MAX_LAG` | none; reports the drain command |
| `orion_dead_letters` | error when any | none; reports the retry command |
| `orion_receipts_verify` | error on a break, warn when receipts are off | none |

## Writing to the outbox

Record into the outbox in the transaction that writes the entity, as its last statement. Dispatch once that
transaction has committed:

```php
$entry = $databases->database()->transaction(function ($database) use ($product, $outbox) {
    $database->insert('products')->values($product)->run();

    return $outbox->index(IndexName::of('products'), $product['id'], $projector->project($product));
});
$dispatcher->dispatch(IndexName::of('products'));
```

Open the host's transactions through Cycle (`$database->transaction()`, `begin()`, the ORM's unit of work), not on
the raw PDO handle: Cycle counts the nesting, and Orion joins it as a savepoint.

`examples/univeros` is a running host built this way, with a walkthrough.
