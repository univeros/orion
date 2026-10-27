# Index lifecycle

Change an index's schema safely: plan the change, apply what can be applied in place, rebuild the index with no
downtime when it cannot, drop the old version afterwards, and prove every step with signed receipts. Each
operation is shown in PHP and with its `bin/orion` command.

## Versions behind an alias

Your application only ever names the alias (`products`). Behind it, Orion keeps versioned physical indices:

```
alias:     products
physical:  products_v1   (current)
           products_v2   (built during a reindex, then swapped in)
```

Orion records every version and the alias in its own tables (`orion_index`, `orion_alias`), so it always knows
which physical index serves the alias and which schema it was built with. On Elasticsearch, OpenSearch and Solr
the engine's native alias is moved too; on Meilisearch and the in-memory engine, which have no aliases, Orion's
table is the only indirection. The design is in [spec §8](../spec/orion-spec.md).

## Plan

A plan diffs the schema you declare against the live index and classifies every change. Planning changes nothing.

```php
$plan = $graph->planner('elasticsearch')->plan($schema);   // Orion\Schema\MigrationPlan

$plan->isEmpty();          // nothing to do
$plan->additive();         // list<Change>: new fields, applied in place
$plan->breaking();         // list<Change>: everything else, needs a reindex
$plan->requiresReindex();  // true when anything is breaking
$plan->toArray();          // ['index' => 'products', 'changes' => [['path', 'kind', 'classification', 'before', 'after'], ...]]
```

```sh
bin/orion plan products
```

Adding a field is additive. Removing one, or changing its type, language, folding, flags or vector shape, is
breaking, as is any change to the org key or the native settings. Classification is total and fails closed: what
Orion cannot recognise as additive is breaking.

## Apply

```php
$outcome = $graph->planner('elasticsearch')->apply($schema);   // Orion\Lifecycle\Outcome

$outcome->operation;      // Orion\Lifecycle\Operation
$outcome->registration;   // the version now current: physical, version, schema hash
$outcome->plan;           // what was applied, or what could not be
$outcome->receipt;        // ?Orion\Receipt\Receipt, when receipts are on
```

| `operation` | What happened |
| --- | --- |
| `Created` | The alias did not exist: version 1 was built and the alias points at it |
| `Applied` | Additive changes were applied to the live index in place (or only query-time attributes such as `boost` changed) |
| `Unchanged` | Nothing differed |
| `ReindexRequired` | The plan is breaking, or the engine refused an additive change in place. **Nothing was changed** |

`apply()` never touches a live index for a breaking change. It is also refused while a reindex of the alias is in
progress, and when the schema does not fit the tenancy (an org key under single tenancy, or none under a resolver).

```sh
bin/orion apply products --dry-run   # what it would do: create, apply, nothing or reindex_required
bin/orion apply products             # exits 3 when a reindex is required
```

`ReindexRequired` is an answer, not an error: `bin/orion apply` exits with code 3 ("action required") rather
than 1.

## Reindex with zero downtime

A reindex rebuilds the index from the outbox, not from the old index, so it works for any change, a new analyzer
included:

```php
$result = $graph->reindexer('elasticsearch')->reindex($changedSchema);   // Orion\Lifecycle\ReindexResult

$result->fromVersion;   // 1
$result->toVersion;     // 2
$result->physical;      // products_v2
$result->documents;     // writes replayed
$result->toOffset;      // the last outbox offset replayed
$result->receipt;       // the signed receipt, when receipts are on
```

```sh
bin/orion reindex products --dry-run   # the plan, the next version's name and how many entries it would replay
bin/orion reindex products
```

What happens, in order:

1. The next version (`products_v2`) is registered and created from the new schema.
2. The outbox is replayed into it from offset 0, in batches, until a read comes back short.
3. The alias is swapped onto it atomically.
4. The outbox is replayed once more from where the first replay stopped, catching any write committed just before
   the swap. From then on the drain writes to the new version.

Searches keep using the old version until the swap, and writes keep flowing throughout. Every document carries
its outbox offset as its version, so a replayed write and a live write of the same document converge on the newer
one, whatever order they arrive in. The full argument and its proof (the fault-injection and determinism suites)
are in [spec §8.3 and §18](../spec/orion-spec.md).

**One reindex per alias at a time.** The job holds a lease in `orion_reindex`, renewed every batch. Running
`reindex` again while another run holds the lease fails; once a run dies and its lease expires (5 minutes), the
next `reindex` with the same schema resumes from the last offset it replayed.

**When the new version refuses documents**, the reindex stops before swapping and throws
`Orion\Lifecycle\ReplayRefused`: the alias still points at the old version, which is complete. Fix the documents
(record corrected ones; a newer entry replaces a refused one) or the schema, and run `reindex` again to resume.

**To give up** an interrupted reindex and drop the version it was building:

```php
$graph->reindexer('elasticsearch')->abort(IndexName::of('products'));   // the version dropped, or null
$graph->reindexer('elasticsearch')->pending(IndexName::of('products')); // ?ReindexJob: the job in progress
```

```sh
bin/orion reindex products --abort
```

**The org key cannot change by reindex** once the index holds documents: they were recorded under the old key.
Declare a new index with the new key and record every document again.

## Drop old versions

After a swap the old version stays, so you can compare or roll back by hand. Prune it once a grace period has passed:

```php
$reindexer = $graph->reindexer('elasticsearch');
$reindexer->prunable(IndexName::of('products'), graceSeconds: 300);   // what would be dropped now
$reindexer->prune(IndexName::of('products'), graceSeconds: 300);      // drops them, one receipt per drop
```

```sh
bin/orion prune products --dry-run
bin/orion prune products --grace=3600
```

The grace window counts from the alias's last swap (300 seconds by default on the CLI). A version a pending
reindex is building is never pruned.

## Drift

Drift is a declared schema that no longer matches its index. `bin/orion drift` checks every index the `search:`
manifests declare (or the files given with `--schema`):

```sh
bin/orion drift --check     # exits 3 when any index is missing or drifted
```

Each index reports `missing`, `in_sync`, `additive` or `breaking`. Run it in CI or a deploy step to catch a schema
change that nobody applied.

## Receipts

Every operation that changes an index or its registration (create, apply, reindex, drop) can issue a **receipt**:
a record of what was done (the operation, alias, engine, versions, document count, schema hash and outbox offsets),
hash-chained to the receipt before it and signed with Ed25519. Receipts are stored in `orion_receipt`,
append-only, in the transaction that records the operation, so the alias never moves without one.

Receipts are on when the `Config` has a signer. Generate a key pair once and keep the secret key in your secret
store:

```sh
bin/orion receipts:keygen --kid=prod-1
# kid: prod-1
# public_key: <base64>
# secret_key: <base64>
```

```php
use Orion\Receipt\SodiumSigner;

$config = new Config(
    storage: $storage,
    engines: $engines,
    tenancy: Tenancy::single(),
    receiptSigner: SodiumSigner::fromBase64(getenv('ORION_RECEIPT_SECRET_KEY'), 'prod-1'),
);
```

To rotate, sign with a new kid and pass the earlier public keys, so older receipts still verify:
`SodiumSigner::fromBase64($newSecret, 'prod-2', ['prod-1' => $oldPublicKey])`.

Without a signer the lifecycle works the same and issues no receipts.

## Verify

```php
$verification = $graph->verifier()->verify();   // Orion\Receipt\Verification

$verification->ok();        // true when nothing is wrong
$verification->breaks;      // list<string>: each problem found
$verification->receipts;    // how many receipts were checked
$verification->head;        // the last receipt: record its sequence and hash elsewhere
```

```sh
bin/orion verify
bin/orion verify --head=42:9f2c...   # the chain must still reach a head you recorded elsewhere
```

Verification walks the whole chain: every hash, every link, every signature, no gap in the sequence, and that each
alias's current version and schema is what its last receipt attests. A change made with receipts off, or written
into the registry by hand, shows up as a break. Anchoring the head (`--head`, or
`verify($anchorSequence, $anchorHash)`) also catches a chain whose tail was cut.

An auditor does not need the signing key. Give them a configuration with public keys only:

```php
use Orion\Receipt\SodiumVerifier;

$config = new Config(
    storage: $storage,
    engines: $engines,
    tenancy: Tenancy::single(),
    receiptVerifier: SodiumVerifier::fromBase64(['prod-1' => $publicKey]),
);
```

`$graph->verifier()` throws a `LogicException` when the configuration has neither a signer nor a verifier.

## What is deterministic

The lifecycle is: from the same state, the same schema and the same outbox produce the same plan, the same
versions and the same receipt contents (timestamps aside), and the determinism suite proves it on every engine. Ranking is not: Orion never claims that two engines, or
two runs, order hits the same way. See [spec §17](../spec/orion-spec.md).
