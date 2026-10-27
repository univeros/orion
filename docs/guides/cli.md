# CLI reference

Every `bin/orion` command, its arguments and options, and an example. The commands run Orion's operations against
your application's own storage and engines, print YAML for people or canonical JSON for scripts and agents, and
exit with a code a script can branch on.

## Install and point it at your application

```sh
composer require orion-search/cli
```

The binary is `vendor/bin/orion`. It needs your application's configuration: a PHP file that returns your
`Orion\Wiring\Config` (or an `Orion\Orion`):

```php
<?php
// orion.php
require __DIR__ . '/vendor/autoload.php';

return new Orion\Wiring\Config(
    storage: new Orion\Pdo\PdoStorage(new PDO(getenv('DATABASE_DSN'), getenv('DATABASE_USER'), getenv('DATABASE_PASSWORD'))),
    engines: new Orion\Registry\EngineRegistry(elasticsearch: Orion\Elasticsearch\ElasticsearchEngine::connect(getenv('ELASTICSEARCH_URL'))),
    tenancy: Orion\Tenancy\Tenancy::single(),
    receiptSigner: Orion\Receipt\SodiumSigner::fromBase64(getenv('ORION_RECEIPT_SECRET_KEY'), 'prod-1'),
);
```

Pass it with `--bootstrap=orion.php` (`-b`) or set `ORION_BOOTSTRAP=orion.php` once. In a Univeros application the
same commands are `bin/altair orion:<command>` and need no bootstrap ([Frameworks](frameworks.md#univeros)).

## Options every command takes

| Option | Meaning |
| --- | --- |
| `--json` | Print canonical JSON (keys sorted at every depth) instead of YAML: the same state prints the same bytes |
| `--bootstrap=<file>`, `-b` | The PHP file returning your `Config` or `Orion`; or set `ORION_BOOTSTRAP` |

Shared by several commands:

| Option | Meaning |
| --- | --- |
| `--schema=<file>`, `-s` | The schema, as a `search:` manifest or the schema's array form, YAML or JSON. Without it, the index's `search:` manifest |
| `--engine=<name>`, `-e` | The engine; defaults to the one that built the index, then the manifest's `engine`, then the only one configured |
| `--dry-run` | Report what would happen and change nothing |

## Exit codes

| Code | Meaning |
| --- | --- |
| 0 | Done |
| 1 | Failed. The error is printed as data: `error.type` (the exception's short name) and `error.message` |
| 3 | Action required: a breaking change needs a reindex (`apply`), or drift was found (`drift --check`) |

```sh
$ bin/orion status products --json        # no --bootstrap, no ORION_BOOTSTRAP
{
    "error": {
        "message": "Pass --bootstrap=<file> or set ORION_BOOTSTRAP: a PHP file returning your Orion\\Orion or Orion\\Wiring\\Config.",
        "type": "RuntimeException"
    }
}
$ echo $?
1
```

## Schema lifecycle

### `plan <index>`

Diff the declared schema against the live index and classify every change. Changes nothing.

Options: `--schema`, `--engine`.

```sh
$ bin/orion plan products
index: products
engine: meilisearch
state: missing            # missing, in_sync, additive or breaking
requires_reindex: false
plan:
  index: products
  changes:
    - { path: brand, kind: added, classification: additive, before: null, after: { type: keyword, filterable: true, facetable: true } }
    ...
```

### `apply <index>`

Create the index, or apply additive changes in place. A breaking change is refused (exit 3) and nothing changes.

Options: `--schema`, `--engine`, `--dry-run`.

```sh
$ bin/orion apply products --dry-run
index: products
engine: meilisearch
dry_run: true
would: create             # create, apply, nothing or reindex_required
plan: { ... }

$ bin/orion apply products
operation: created
index: products
physical: products_v1
version: 1
schema_hash: 85985bf6...
plan: { ... }
receipt: { sequence: 1, operation: created, ... }   # null when receipts are off
```

After a breaking change, `apply` prints `operation: reindex_required` with the plan and exits 3.

### `reindex <index>`

Rebuild the index from the outbox with the declared schema into the next version, swap the alias, and issue a
receipt. Resumes an interrupted reindex of the same schema.

Options: `--schema`, `--engine`, `--dry-run`, `--batch=<n>` (entries per replay batch, default 500), `--abort`
(give up an interrupted reindex and drop the version it was building).

```sh
$ bin/orion reindex products --dry-run
index: products
engine: meilisearch
dry_run: true
requires_reindex: true
plan: { ... }
pending: null             # the interrupted reindex, if any
next: products_v2
entries: 3                # outbox entries a replay would read

$ bin/orion reindex products
index: products
from_version: 1
to_version: 2
physical: products_v2
documents: 3
to_offset: 4
receipt: { sequence: 2, operation: reindexed, ... }
```

### `prune <index>`

Drop the versions the alias no longer points at, once the grace window since the last swap has passed. Each drop
is receipted.

Options: `--grace=<seconds>` (default 300), `--dry-run`.

```sh
$ bin/orion prune products --grace=0
index: products
grace: 0
dropped:
  - products_v1
```

### `drift`

Report drift between the declared schemas and their indices: every index the `search:` manifests declare, or the
files given.

Options: `--schema=<file>` (repeatable, one per index), `--engine`, `--check` (exit 3 when any index drifted or is
missing).

```sh
$ bin/orion drift --check
in_sync: false
indices:
  - { index: products, engine: meilisearch, state: breaking }
$ echo $?
3
```

### `status <index>`

Describe an index: its current version, every version, when the alias last swapped, outbox head, drain position,
lag and dead letters, a pending reindex, the receipt count and the engine's capabilities.

```sh
$ bin/orion status products
index: products
engine: meilisearch
current: { physical: products_v1, version: 1, schema_hash: 85985bf6... }
versions:
  - { physical: products_v1, version: 1, schema_hash: 85985bf6..., created_at: '2026-10-27T17:58:01+00:00' }
swapped_at: '2026-10-27T17:58:01+00:00'
outbox: { head: 3, drained_to: 3, lag: 0, dead_letters: 1 }
reindex: null
receipts: 1
capabilities: { Vectors: true, HybridFusion: false, ... }
```

### `flush <index>`

Refresh or commit the index behind the alias and wait, so recent writes are searchable now. An honest no-op where
the engine has nothing to flush.

```sh
$ bin/orion flush products
index: products
flushed: products_v2
```

### `capabilities [<engine>]`

Print the capability matrix of each configured engine, or of one ([Engines](engines.md#capability-matrix)).

```sh
$ bin/orion capabilities meilisearch
meilisearch:
  Vectors: true
  HybridFusion: false
  NestedDocuments: false
  Facets: true
  Highlighting: true
  NativeAliases: false
  AsyncWrites: true
  CursorPagination: false
  FunctionScore: false
  Streaming: false
  OptimisticConcurrency: true
```

## The outbox

### `outbox:drain [<index>]`

Write committed outbox entries to their indices, every built index when none is named, and report the lag.

Options: `--batch=<n>` (entries per batch, default 500), `--dry-run` (report the position and lag only).

```sh
$ bin/orion outbox:drain
indices:
  - { index: products, from: 0, to: 3, head: 3, lag: 0, applied: 2, stale: 0, failed: 1 }
```

`failed` documents became dead letters; the command still exits 0, because the drain itself succeeded. Run it on a
schedule (cron, a systemd timer, your queue's scheduler) even if you dispatch after each commit.

### `outbox:retry <index>`

Resend the dead letters of an index. With `--dry-run`, list them.

```sh
$ bin/orion outbox:retry products --dry-run
index: products
dry_run: true
dead_letters:
  - { index: products, offset: 3, document: p3, error: '"colour" is not a declared field.', attempts: 1 }

$ bin/orion outbox:retry products
index: products
applied: 0
stale: 0
failed: 0
superseded: 0           # letters whose document has a newer entry, dropped
```

## Receipts

### `verify`

Verify the receipt chain: every hash, link and signature, its length, and that it attests every alias's current
version. Exits 1 on any break.

Options: `--head=<sequence>:<hash>` (a head you recorded elsewhere, which the chain must still reach).

```sh
$ bin/orion verify
receipts: 3
ok: true
breaks: []
head: { sequence: 3, hash: 164cd525... }

$ bin/orion verify --head=3:164cd5253151dd168cbedd423d7dfecc21e4b55b275ab95af3ab46b7ac0927f0
```

Verification needs keys: a `receiptSigner` or, for an auditor, a `receiptVerifier` with public keys only, in the
bootstrap's `Config`.

### `receipts:keygen`

Generate an Ed25519 key pair for signing receipts. Needs no bootstrap.

Options: `--kid=<id>` (the key id receipts will name, default `orion-1`).

```sh
$ bin/orion receipts:keygen --kid=prod-1 --json
{
    "kid": "prod-1",
    "public_key": "POF/4UxZ...",
    "secret_key": "i3xwzypS..."
}
```

Keep the secret key in your secret store; give auditors the public key.

## In scripts and CI

```sh
bin/orion drift --check --json || exit $?             # fail the deploy on unapplied schema changes
bin/orion apply products --json                       # exit 3 means: schedule a reindex
bin/orion reindex products --dry-run --json | jq .next
bin/orion verify --json | jq -e .ok
```

The JSON output is canonical: the same state and arguments print the same bytes, so it diffs cleanly and can be
compared across runs.
