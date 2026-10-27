# Indexing

Write, update and delete documents so the index never disagrees with your database: record each change in the
transactional outbox next to the entity write, drain it to the engine after the commit, and handle what the engine
refuses. This guide also covers projecting entities into documents and running the drain in production.

## How writes reach the engine

```
your transaction:  save the entity  +  $outbox->index(...)        (one commit, one connection)
after the commit:  $dispatcher->dispatch($index)  ->  the drain  ->  the engine
```

The outbox is a table on the connection your application already writes on (`orion_outbox`, installed by
`Orion\Pdo\Installer` or your framework's migration). An entry commits or rolls back with the entity, so the engine
only ever receives committed state, and the outbox doubles as the source a reindex replays from. Why it is built
this way: [spec §10](../spec/orion-spec.md) and [revision §4](../spec/revision.md).

## Index a document

```php
use Orion\IndexName;

$products = IndexName::of('products');

$pdo->beginTransaction();
saveProduct($pdo, $product);                            // your own write
$graph->outbox()->index($products, $product->id, [
    'title' => $product->title,
    'brand' => $product->brand,
    'price' => $product->price,
]);
$pdo->commit();

$graph->dispatcher()->dispatch($products);
```

`Outbox::index(IndexName $index, string $id, array $fields)` takes the alias, your document id and the document
as an array of field values. The fields must be an object of JSON-encodable values and must fit the schema:

- a field the schema does not declare fails that document (every engine's mapping is strict);
- a value of the wrong type (a string in an integer field, a vector of the wrong length) fails that document;
- dates are ISO 8601 strings, geo points `['lat' => ..., 'lon' => ...]`, vectors lists of floats;
- an id is at most 255 bytes, less under a tenant (the tenant is prefixed to it).

The failures are reported when the drain writes the batch (see [When the engine refuses a
document](#when-the-engine-refuses-a-document)), not when you record.

**Use the same connection and transaction.** `PdoStorage` joins a transaction already open on its PDO handle (as a
savepoint) instead of starting another, which is what makes the entry commit with your entity. If no transaction
is open, `index()` runs in its own and commits at once.

**Record as the last statement of the transaction.** Each index has an offset counter, and a transaction that
records into an index holds that counter until it commits, so offsets stay in commit order. Keep the hold short.
If one transaction records into several indices, record them in the same order everywhere: two transactions
taking the same counters in opposite orders can deadlock, and the database aborts one.

## Update and delete

Updating is indexing again with the same id. The new document replaces the old one whole; there are no partial
updates:

```php
$graph->outbox()->index($products, 'p1', ['title' => 'Trail running shoe, v2', 'brand' => 'Acme', 'price' => 79.0]);
```

Deleting:

```php
$graph->outbox()->delete($products, 'p1');
```

The outbox keeps one entry per document, the latest, so a document written a thousand times costs one row, and a
deleted document keeps its delete entry. Every entry carries its offset as the document's version, and engines
apply a write only when it is newer than what they hold. Writes that arrive late or twice (a retried batch, a
replay overlapping live writes) therefore change nothing; they are counted as `stale`.

You can record entries before the index exists. They wait in the outbox, and the first drain after `apply()`
writes them.

## Drain the outbox

The drain writes an index's entries to the engine in offset order and in batches, and keeps a cursor per index.
There are three ways to run it:

1. **Right after the commit**, with `$graph->dispatcher()->dispatch($index)`. The default dispatcher drains at once,
   in your process. Never dispatch inside the transaction: the drain would read entries that might still roll back.
2. **On a queue.** Pass your own `Orion\Outbox\Dispatcher` (one method, `dispatch(IndexName $index): void`) as
   `Config::$dispatcher`, and have the job call `$graph->drain()->run($index)`. The Univeros module does this on its
   message bus.
3. **On a schedule**, with `bin/orion outbox:drain` (every built index) or `bin/orion outbox:drain products`.

Run the scheduled drain even when you dispatch after each commit: if the engine is down at dispatch time, the
entries stay in the outbox, and the next drain picks them up. Dispatching can throw when the engine is unreachable,
so catch and log it rather than failing the request that already committed:

```php
try {
    $graph->dispatcher()->dispatch($products);
} catch (Throwable $failure) {
    $logger->warning('Drain failed; the outbox keeps the write.', ['exception' => $failure]);
}
```

What a drain reports:

```php
$result = $graph->drain()->run($products, batch: 500);   // Orion\Outbox\DrainResult
$result->applied;   // documents written
$result->stale;     // writes the engine already had a newer version of
$result->failed;    // documents the engine refused (now dead letters)
$result->lag;       // committed entries still behind the cursor

$graph->drain()->runAll();                // every built index
$graph->drain()->status($products);       // the cursor, head and lag, without draining
```

Each run stops at the outbox head it saw when it started, so it always finishes, and reports the rest as `lag`
instead of chasing a busy writer. The cursor moves only after a batch is written: an engine that dies mid-batch
leaves the cursor where it was, and the next run writes the batch again, which versions make harmless.

## When the engine refuses a document

A document the engine refuses (an undeclared field, a wrong type) becomes a **dead letter** in
`orion_dead_letter`, with the engine's reason. The drain moves on; one bad document never blocks the index.

```php
foreach ($graph->deadLetters()->of($products) as $letter) {
    echo $letter->documentId, ': ', $letter->error, ' (', $letter->attempts, ' attempts)', PHP_EOL;
}
// p3: "colour" is not a declared field.

$graph->deadLetters()->count();             // across every index
$graph->deadLetters()->retry($products);    // Orion\Outbox\Retried: applied, stale, failed, superseded
```

To fix a dead letter, record a corrected version of the document (or a delete): the next drain writes it and
clears the letter, because what matters is the document's latest state. If the schema was wrong instead, change it
and [reindex](index-lifecycle.md). `retry()` (`bin/orion outbox:retry products`) resends the letters as they are,
for failures that were the engine's fault, such as a timeout.

## Projections: entity to document

A projector turns one of your entities into a document. Orion defines the contract and the manifest can name the
class, but core never instantiates it: you call it where you record.

```php
use Orion\Projection\Projector;
use Orion\Write\Document;

final class ProductProjector implements Projector
{
    public function project(object $entity): Document
    {
        if (!$entity instanceof Product) {
            throw new InvalidArgumentException('ProductProjector projects products.');
        }

        return new Document(
            id: (string) $entity->id,
            fields: [
                'title' => $entity->title,
                'brand' => $entity->brand->name,
                'price' => $entity->price->amount / 100,
                'in_stock' => $entity->stock > 0,
            ],
            version: 0,   // the outbox stamps the real version, its offset
        );
    }
}

$document = $projector->project($product);
$graph->outbox()->index($products, $document->id, $document->fields);
```

The interface takes `object`, so a projector checks its own type (PHP does not let an implementation narrow the
parameter to `Product`). `Document::$version` is what the engine is sent, but on this path the outbox replaces it
with the entry's offset, so any non-negative value works.

To make the projector discoverable to hosts and agents, name it in the manifest:

```yaml
index: products
source:
  entity: App\Domain\Product
  projector: App\Search\ProductProjector
schema: { ... }
```

`$graph->manifest()->find('products')->source` returns it as an `Orion\Manifest\Source`. The spec's reasoning for
real classes over configuration is in [§13](../spec/orion-spec.md).

## Batching and throughput

- The drain sends up to `Drain::DEFAULT_BATCH` (500) entries per bulk request; pass `batch:` to `run()` or `--batch`
  to `bin/orion outbox:drain` to change it.
- A reindex replays in batches of 500 too (`--batch` on `bin/orion reindex`).
- Asynchronous engines (Meilisearch) process each bulk write as a task; Orion waits up to 30 seconds for it before
  counting the batch.
- Recording is one row per call. There is no bulk-record call because there is no need for one: entries are cheap
  inserts inside a transaction you already have.

## Watching it in production

Pass an `Orion\Observability\Metrics` implementation as `Config::$metrics` (two methods, `counter()` and
`gauge()`) to receive, per drain run and with an `index` attribute:

| Metric | Kind | Meaning |
| --- | --- | --- |
| `orion.outbox.applied` | counter | documents written |
| `orion.outbox.stale` | counter | writes superseded by a newer version |
| `orion.outbox.failed` | counter | documents refused, now dead letters |
| `orion.outbox.lag` | gauge | committed entries not yet written |

`bin/orion status products` shows the outbox head, the drain cursor, the lag and the dead-letter count for one
index.
