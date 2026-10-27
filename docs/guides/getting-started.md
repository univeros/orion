# Getting started

Install Orion, define a first index, write a few documents through the outbox and search them, first on the
in-memory development engine and a SQLite file, then on a real engine. It takes about ten minutes, and none of it
needs a running server until the last step.

## What you need

- PHP 8.3 or later with `ext-sodium` (core signs receipts with it) and `ext-pdo_sqlite` for this guide.
- Composer.
- For the last step, Docker (or any Meilisearch, Elasticsearch, OpenSearch or SolrCloud server you can reach).

## Install

Every application needs the core, a storage adapter and, sooner or later, the CLI:

```sh
composer require orion-search/core orion-search/pdo orion-search/cli
```

Then one engine adapter per engine you run. Each brings its own client:

| Engine | Package | Client it installs |
| --- | --- | --- |
| Elasticsearch 8.19, 9.x | `orion-search/elasticsearch` | `elasticsearch/elasticsearch` (install the major that matches your server) |
| OpenSearch 2.19, 3.x | `orion-search/opensearch` | `opensearch-project/opensearch-php` with Guzzle |
| Meilisearch 1.13+ | `orion-search/meilisearch` | `meilisearch/meilisearch-php` with Guzzle |
| SolrCloud 9.x, 10.x | `orion-search/solr` | `solarium/solarium` (needs `ext-curl`) |

The in-memory engine used below ships with `orion-search/core`; nothing else is needed for it.

> Until the packages are published on Packagist, install them from a clone of
> [univeros/orion](https://github.com/univeros/orion) with a Composer `path` repository pointing at its
> `packages/*`, and require them as `^1.0@dev`. [`examples/univeros/composer.json`](../../examples/univeros/composer.json)
> is a working example.

## Wire Orion

`Orion::create()` takes a `Config` and builds everything else. Three settings have no default because each one is
a correctness or trust boundary: where Orion stores its state (`storage`), which engines it talks to (`engines`),
and whose data a query may see (`tenancy`).

```php
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Orion;
use Orion\Pdo\Installer;
use Orion\Pdo\PdoStorage;
use Orion\Registry\EngineRegistry;
use Orion\Tenancy\Tenancy;
use Orion\Wiring\Config;

$pdo = new PDO('sqlite:' . __DIR__ . '/orion.sqlite');
Installer::install($pdo);                  // creates the orion_* tables; safe to run again

$orion = Orion::create(new Config(
    storage: new PdoStorage($pdo),         // the same connection your application writes its entities on
    engines: new EngineRegistry(memory: new InMemoryEngine()),
    tenancy: Tenancy::single(),            // one tenant; see the multi-tenancy guide for more
));
$graph = $orion->graph();
```

Engines are registered by name (`memory:` here) and you pick the name. Lifecycle services take it:
`$graph->planner('memory')`, `$graph->reindexer('memory')`.

`InMemoryEngine` keeps its indices in PHP memory, so they live as long as the process. It is for development and
tests, and it passes the same conformance suite as the real engines.

## Define and create an index

A schema is code. Every builder call returns a new, immutable schema:

```php
use Orion\Schema\IndexSchema;

$schema = IndexSchema::for('products')
    ->text('title', language: 'en', boost: 2.0)
    ->text('description', language: 'en')
    ->keyword('brand', filterable: true, facetable: true)
    ->float('price', filterable: true, sortable: true)
    ->boolean('in_stock', filterable: true);

$outcome = $graph->planner('memory')->apply($schema);

echo $outcome->operation->value;             // created
echo $outcome->registration->physical->value; // products_v1
```

Orion never hands you the physical index. It builds `products_v1` and points the alias `products` at it, and your
code only ever names `products`. When the schema later changes in a way that needs a rebuild, Orion builds
`products_v2` beside it and swaps the alias. See [Index lifecycle](index-lifecycle.md).

## Index some documents

Documents go to the engine through the **outbox**: a table on your database connection. Record the document in the
same transaction that saves your entity, then drain the outbox once the transaction has committed:

```php
use Orion\IndexName;

$products = IndexName::of('products');

$pdo->beginTransaction();
// ... save the products in your own tables here ...
$graph->outbox()->index($products, 'p1', ['title' => 'Trail running shoe', 'description' => 'Light shoe for rough trails', 'brand' => 'Acme', 'price' => 89.0, 'in_stock' => true]);
$graph->outbox()->index($products, 'p2', ['title' => 'Road running shoe', 'description' => 'Cushioned shoe for the road', 'brand' => 'Acme', 'price' => 120.0, 'in_stock' => true]);
$graph->outbox()->index($products, 'p3', ['title' => 'Hiking boot', 'description' => 'Waterproof boot for long trails', 'brand' => 'Peak', 'price' => 150.0, 'in_stock' => false]);
$pdo->commit();

$graph->dispatcher()->dispatch($products);   // writes the committed entries to the engine
```

If the transaction rolls back, the outbox entries roll back with it, and the engine never sees a document your
database does not have. The [Indexing guide](indexing.md) covers updates, deletes, batching and failures.

## Search

```php
use Orion\Query\Direction;
use Orion\Query\Filter;
use Orion\Query\Query;

$results = $graph->searcher()->search(
    Query::on('products')
        ->match(['title', 'description'], 'trail shoe')
        ->filter(Filter::term('in_stock', true))
        ->facet('brand')
        ->sort('price', Direction::Desc)
        ->highlight('title')
        ->paginate(1, 10),
);

echo $results->total;                        // 2
foreach ($results->hits as $hit) {
    echo $hit->id, ' ', $hit->source['title'], PHP_EOL;
}
// p2 Road running shoe
// p1 Trail running shoe

$results->facets;                            // ['brand' => ['Acme' => 2]]
$results->hits[1]->highlights;               // ['title' => ['<em>Trail</em> running <em>shoe</em>']]
```

A `match` finds documents that contain any of its words. The query is checked against the schema before it
reaches the engine: sorting by a field not declared `sortable`, or filtering an integer with a string, throws an
`InvalidArgumentException` that names the field. The [Searching guide](searching.md) covers the whole query model.

To see what the engine would receive without running the query:

```php
$graph->searcher()->compile(Query::on('products')->match('title', 'boot'))->native;
```

## Switch to a real engine

Only the engine registry changes. Start a Meilisearch server:

```sh
docker run -d -p 7700:7700 -e MEILI_ENV=development getmeili/meilisearch:v1.56.0
composer require orion-search/meilisearch
```

Then register it instead of (or beside) the in-memory engine:

```php
use Orion\Meilisearch\MeilisearchEngine;

$orion = Orion::create(new Config(
    storage: new PdoStorage($pdo),
    engines: new EngineRegistry(meilisearch: MeilisearchEngine::connect('http://localhost:7700')),
    tenancy: Tenancy::single(),
));
$graph = $orion->graph();
$graph->planner('meilisearch')->apply($schema);
```

Every other line above stays the same. Two things differ in practice:

- A real engine keeps its data between processes, and so does the SQLite file, so the index and the outbox survive
  a restart. The in-memory engine forgets its indices when the process ends while the registry in `orion.sqlite`
  remembers them, so delete the file (or use a fresh one) when you go back to the in-memory engine.
- Engines make writes searchable after a short refresh (about a second on Elasticsearch, OpenSearch and Solr;
  Meilisearch processes writes as tasks, which Orion waits for). `bin/orion flush products` forces it.

[Engines](engines.md) shows how to connect each engine and what each one supports.

## Run it from the shell

`bin/orion` runs the same operations against your application. Give it a bootstrap file that returns your
`Config` (or `Orion`):

```php
<?php
// orion.php
require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO('sqlite:' . __DIR__ . '/orion.sqlite');

return new Orion\Wiring\Config(
    storage: new Orion\Pdo\PdoStorage($pdo),
    engines: new Orion\Registry\EngineRegistry(meilisearch: Orion\Meilisearch\MeilisearchEngine::connect('http://localhost:7700')),
    tenancy: Orion\Tenancy\Tenancy::single(),
);
```

```sh
vendor/bin/orion status products --bootstrap=orion.php
vendor/bin/orion outbox:drain --bootstrap=orion.php
```

Every command is listed in the [CLI reference](cli.md).

## Next

- [Index schemas](index-schemas.md): every field type and option, and schemas as YAML manifests.
- [Indexing](indexing.md): the outbox, projections, batching and failures.
- [Searching](searching.md): the query model in depth.
- [Index lifecycle](index-lifecycle.md): changing a schema, reindexing without downtime, and receipts.
