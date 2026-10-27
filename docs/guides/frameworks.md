# Frameworks

Plug Orion into a web application: serve the `search:` endpoints a manifest declares from any PSR-15 stack, or
install the Univeros module, which wires storage, console commands, the endpoints, the doctor, the message bus and
the MCP tools with one line. Orion's core needs no framework; these packages are the integrations that exist today.

## The search: endpoints over PSR-15

`orion-search/psr15` turns each manifest's `search` block into an HTTP endpoint, served through the tenant-scoped
searcher, with failures as RFC 9457 problem responses.

```sh
composer require orion-search/psr15
```

### Declare the endpoint

The `search` block sits beside the schema in the index's manifest:

```yaml
# api/products/search.yaml
index: products
schema:
  title:       { type: text, language: en, boost: 3.0 }
  description: { type: text, language: en }
  brand:       { type: keyword, filterable: true, facetable: true }
  price:       { type: float, filterable: true, sortable: true }
  in_stock:    { type: boolean, filterable: true }
search:
  endpoint: GET /products/search
  permission: products.search        # optional: checked by your Authorizer
  query_fields: { title: 2.0, description: 1.0 }   # or a list: [title, description]
  filterable: [brand, price, in_stock]
  sortable: [price, _score]
  facets: [brand]
  highlight: [title]
  default_sort: _score               # `field` or `-field` for descending
  page_size: 20                      # the largest `size` a request may ask for
```

Every field is checked against the schema when the manifest loads: a field listed under `filterable` must be
declared `filterable`, and so on, so the endpoint can only ever expose what the schema allows. For hybrid search,
add `hybrid: { vector_field: embedding, fusion: reciprocal_rank }` and serve the endpoint with `POST`: the vector
travels in the JSON body. Only reciprocal-rank fusion is accepted.

### Mount the middleware

```php
use Orion\Psr15\SearchMiddleware;

$search = new SearchMiddleware(
    $graph->manifest(),
    $graph->searcher(),
    $responseFactory,      // PSR-17 ResponseFactoryInterface
    $streamFactory,        // PSR-17 StreamFactoryInterface
    $authorizer,           // optional Orion\Psr15\Authorizer, for endpoints that declare a permission
    $logger,               // optional PSR-3 logger, for 500s
    prefix: '/api',        // where the routes are mounted: GET /api/products/search
);
```

The middleware answers the declared routes and passes every other request to the next handler. A declared path
requested with another method gets a 405.

### Call it

A `GET` endpoint reads the query string; a `POST` endpoint a JSON body with the same keys:

| Key | Meaning |
| --- | --- |
| `q` | Text matched over the endpoint's `query_fields` (at most 64 words); highlights apply when it is given |
| `filter[<field>]` | One value for a term filter, a list for terms, or `gt`/`gte`/`lt`/`lte` bounds for a range; only `filterable` fields |
| `sort` | `field` or `-field`, several comma-separated or as a list; only `sortable` fields |
| `page`, `size` | 1-based page, and a size of at most `page_size` |
| `vector` | The query embedding, on a hybrid endpoint only |

```
GET /api/products/search?q=trail+shoe&filter[brand]=Acme&filter[price][lte]=100&sort=-price&page=1&size=10
```

Any other parameter is a 400, so a typo never silently drops a filter. The response body is the result envelope
(`ResultSet::toArray()`: `hits`, `total`, `total_is_estimate`, `facets`, `took_ms`).

| Failure | Status | Problem type |
| --- | --- | --- |
| No tenant in the request | 401 | `no-tenant` |
| The permission the endpoint declares is missing | 403 | `forbidden` |
| Input that does not fit the endpoint or the schema | 400 | `invalid-request` |
| A clause the engine cannot run, a page past the window | 400 | `unsupported-clause` |
| A declared path with another method | 405 | `method-not-allowed` |
| Configuration that does not fit, an engine failure | 500 | `search-failed` (logged) |

### Permissions and tenants

An endpoint that declares a `permission` is checked by your `Orion\Psr15\Authorizer`, one method:
`authorize(ServerRequestInterface $request, string $permission): void`, throwing `Orion\Psr15\Forbidden` to refuse.
Orion adds no authorization of its own.

Under multi-tenancy the tenant comes from the request. Without Polaris, put `Orion\Psr15\RequestTenant` in the
pipeline before the search middleware and configure Orion with it as the resolver; see
[Multi-tenancy](multi-tenancy.md#the-tenant-from-a-psr-15-request).

[Package README](../../packages/psr15/README.md).

## Univeros

`univeros/orion` is Orion as a Univeros module. Install it with the engine package you use:

```sh
composer require univeros/orion orion-search/elasticsearch
```

and add one line to `config/modules.php`:

```php
return [
    new App\AppModule(),
    new Univeros\Orion\Module(),
];
```

### Configure it

Anything the container binds wins over the environment.

| Setting | Environment | Or bind |
| --- | --- | --- |
| Storage | the application's Cycle connection (`DB_*`) | `Orion\Storage\Storage` |
| Engine | `ORION_ENGINE=elasticsearch` + `ORION_ELASTICSEARCH_URL` (comma-separated), `opensearch` + `ORION_OPENSEARCH_URL`, `solr` + `ORION_SOLR_URL`, `meilisearch` + `ORION_MEILISEARCH_URL` and optional `ORION_MEILISEARCH_KEY`, or `memory` (development only) | `Orion\Registry\EngineRegistry` |
| Tenancy | `ORION_TENANCY=single`; with univeros/polaris installed, each request's token decides | `Orion\Tenancy\Tenancy` |
| Receipt signing | `ORION_RECEIPT_KID`, `ORION_RECEIPT_SECRET_KEY` (base64), `ORION_RECEIPT_PREVIOUS_KEYS` (`kid:base64,...`) | `Orion\Receipt\ReceiptSigner` |
| Receipt verification | `ORION_RECEIPT_PUBLIC_KEYS` (`kid:base64,...`) | `Orion\Receipt\ReceiptVerifier` |
| Manifests | `ORION_MANIFESTS` (comma-separated directories; `api` by default) | |
| Endpoint prefix | `ORION_ENDPOINT_PREFIX` | |
| Doctor lag threshold | `ORION_DOCTOR_MAX_LAG` (1000 by default) | |

Tenancy has no default here either: the first use of Orion fails with a message saying what to set until you set
`ORION_TENANCY=single` or install univeros/polaris (with `orion-search/polaris`). Nothing is built until something
resolves it, so `db:migrate` runs before Orion is configured.

### What the module gives the application

- **Storage on the Cycle connection.** The outbox entry commits with the entity. Open transactions through Cycle
  (`$database->transaction()`, `begin()`, the ORM's unit of work), not on the raw PDO handle: Cycle counts the
  nesting and Orion joins it as a savepoint.
- **The migration.** `bin/altair db:migrate` installs Orion's tables.
- **The console.** Every `bin/orion` command as `bin/altair orion:<command>` (`orion:apply`, `orion:outbox:drain`,
  `orion:receipts:keygen`, ...). The application's `bin/altair` adds them with
  `foreach (Univeros\Orion\Console\Commands::all($container) as $command) { $application->add($command); }`.
- **The container.** `Orion\Orion`, `Orion\Wiring\Graph`, `Orion\Outbox\Outbox`, `Orion\Query\Searcher` and
  `Orion\Outbox\Dispatcher` are injectable.
- **The endpoints.** Every manifest `search` block is served, under `ORION_ENDPOINT_PREFIX`, with the Polaris
  `Gate` checking each endpoint's permission when univeros/polaris is configured.
- **The bus.** With univeros/messaging configured, `dispatch()` sends a message and the drain runs on the worker.
- **The Meter.** With univeros/observability, the outbox counters and lag gauge go to the framework Meter.
- **The doctor.** Seven checks join `bin/altair doctor`: engine reachable, registry current, schema drift, alias
  resolves, outbox lag, dead letters, receipts verify; `--fix` applies what is safe (building declared indices,
  additive changes).
- **MCP tools.** With univeros/mcp, the core tools join `bin/altair mcp:serve` as `orion__compile_query`,
  `orion__search`, `orion__list_indices`, `orion__describe_index`, `orion__capabilities`, `orion__plan`,
  `orion__apply` and `orion__reindex`. The two mutating tools preview unless called with `confirm: true`, and
  confirming also needs the server started with `--allow-writes`.
- **The agent manifest.** Orion's section in `bin/altair manifest:generate`.

### Write through the outbox

```php
use Cycle\Database\DatabaseProviderInterface;
use Orion\IndexName;
use Orion\Outbox\Dispatcher;
use Orion\Outbox\Outbox;

final class SaveProduct
{
    public function __construct(private DatabaseProviderInterface $databases, private Outbox $outbox, private Dispatcher $dispatcher)
    {
    }

    public function __invoke(array $product): void
    {
        $index = IndexName::of('products');
        $this->databases->database()->transaction(function ($database) use ($product, $index): void {
            $database->insert('products')->values($product)->run();
            $this->outbox->index($index, $product['id'], ['title' => $product['title'], 'sku' => $product['sku']]);
        });
        $this->dispatcher->dispatch($index);   // after the commit
    }
}
```

[`examples/univeros`](../../examples/univeros/README.md) is a running application built this way, with a
walkthrough. The module's [README](../../packages/univeros/README.md) has the doctor checks in detail.

## Other frameworks

There is no Laravel or Symfony package yet. Orion does not need one: build the `Config` in your service container,
expose `$graph->outbox()`, `$graph->searcher()` and `$graph->dispatcher()` as services, run the outbox in the same
database connection your ORM uses (`PdoStorage` on its PDO handle, or your own `Orion\Storage\Storage`), and point
`bin/orion --bootstrap` at a file that returns the same `Config`. Host packages are added as they are asked for
([revision §1 and §7](../spec/revision.md)).
