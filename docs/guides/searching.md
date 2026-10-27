# Searching

Build a query once and run it on any engine: full-text clauses, filters, facets, sorting, pagination,
highlighting, vector and hybrid retrieval, and the engine's native query when you need more. This guide also shows
how to see exactly what the engine would receive, without sending it.

## The query object

Every search starts with `Query::on()` and goes through the searcher:

```php
use Orion\Query\Direction;
use Orion\Query\Filter;
use Orion\Query\Query;

$query = Query::on('products')
    ->match(['title', 'description'], 'trail shoe')
    ->filter(Filter::range('price', lte: 100))
    ->filter(Filter::term('in_stock', true))
    ->facet('brand')
    ->sort('price', Direction::Asc)
    ->highlight('title')
    ->paginate(page: 1, size: 20);

$results = $graph->searcher()->search($query);   // Orion\Query\ResultSet
```

`Query` is immutable: each call returns a copy, so a base query can be reused. Always search through
`$graph->searcher()`, never an adapter directly: the searcher resolves the alias to the current index, checks the
query against the registered schema, and adds the tenant filter beneath it.

## Full-text clauses

Clauses score the hits. A query's clauses are conjunctive: a hit must match every one of them.

| Clause | Matches | Example |
| --- | --- | --- |
| `match($fields, $text)` | Documents containing any of the analysed words, in any of the fields | `->match('title', 'trail shoe')` |
| `phrase($field, $phrase)` | The words adjacent and in order | `->phrase('title', 'running shoe')` |
| `prefix($field, $prefix)` | A word starting with the prefix | `->prefix('title', 'run')` |
| `term($field, $value)` | The exact, unanalysed value | `->term('sku', 'SKU-123')` |

`match` reads one field, a list of fields at weight 1, or weights by field:

```php
->match('title', 'trail shoe')
->match(['title', 'description'], 'trail shoe')
->match(['title' => 3.0, 'description' => 1.0], 'trail shoe')
```

These weights multiply the schema's `boost`. `match`, `phrase` and `prefix` read `text` and `keyword` fields;
`term` reads `keyword`, `integer`, `float`, `boolean` and `date` fields, never analysed text.

Values are literals. Adapters compile them into structured queries, never into a query string, so a user's input
cannot inject query syntax.

## Filters

Filters restrict the hits without scoring them. They are conjunctive with each other and with the clauses.

```php
Filter::term('brand', 'Acme')
Filter::terms('brand', ['Acme', 'Peak'])                 // any of up to 1000 values
Filter::range('price', gte: 50, lt: 100)                 // gt, gte, lt, lte; at least one
Filter::range('published_at', gte: '2026-01-01')         // dates as ISO 8601 strings
Filter::exists('discount')
Filter::bool(
    must: [Filter::term('in_stock', true)],
    should: [Filter::term('brand', 'Acme'), Filter::term('brand', 'Peak')],   // at least one, when given
    mustNot: [Filter::range('price', gt: 500)],
)
```

A filter's field must be declared `filterable` (except `exists`), and values must fit the field's type. In the
declarative form ([Queries as data](#queries-as-data)), `bool` filters nest up to 10 levels with at most 64 children
per list.

## Sorting

```php
->sort('price', Direction::Desc)
->sort('_score', Direction::Desc)    // relevance; Orion\Query\Sort::SCORE
```

Without a sort, hits come by relevance. Sort fields must be `sortable`, and several sorts apply in order. Documents
missing the sort field come last in either direction.

## Facets

```php
$results = $graph->searcher()->search(
    Query::on('products')->filter(Filter::term('in_stock', true))->facet('brand', size: 10),
);

$results->facets;   // ['brand' => ['Acme' => 2, 'Peak' => 1]]
```

A facet counts values within the hits the query matched, at most `size` buckets (default 10, at most 1000). Its
field must be `facetable`.

## Highlighting

```php
$results = $graph->searcher()->search(Query::on('products')->match('title', 'trail shoe')->highlight('title'));

$results->hits[0]->highlights;   // ['title' => ['<em>Trail</em> running <em>shoe</em>']]
```

Only `text` fields can be highlighted, and only a field that matched gets fragments. Fragments are HTML-safe on
every engine: the stored text is escaped, and `<em>` is the only markup.

## Pagination

```php
->paginate(page: 3, size: 20)    // 1-based page; size at most 1000; default page 1, size 20
```

Pagination is by offset, up to result 10,000 on every engine (`Page::WINDOW`, the lowest engine limit). A page
that reaches past it throws `UnsupportedClause` instead of failing differently on each engine:

```
memory pages by offset to result 10000 at most; this page reaches result 12000. Narrow the query, or wait for cursor pagination.
```

Cursor pagination is not in the query model yet, even on engines that report `Capability::CursorPagination`.

## Results

```php
$results->hits;              // list<Orion\Query\Hit>
$results->total;             // matching documents
$results->totalIsEstimate;   // true when the engine (or core fusion) estimated it
$results->facets;            // counts by field, then by value
$results->tookMs;            // engine time
$results->fusion;            // ?Orion\Query\FusionTrace, for hybrid queries fused in core

$hit = $results->hits[0];
$hit->id;                    // your document id
$hit->score;                 // engine-relative
$hit->source;                // the document's fields (vectors left out)
$hit->highlights;            // fragments by field

$results->toArray();         // JSON-ready: hits, total, total_is_estimate, facets, took_ms
```

Scores are relative to one engine and one query, and the order of equally relevant hits is not deterministic.
Orion makes no claim otherwise ([spec §17](../spec/orion-spec.md)). On Meilisearch, lexical clauses run as filters,
so a lexical-only query is not ranked by relevance; see [Engines](engines.md#meilisearch).

## Vector search

Declare a `vector` field in the schema, store embeddings in your documents, and query with a vector of the same
dimensions:

```php
$results = $graph->searcher()->search(
    Query::on('articles')
        ->vector('embedding', $queryEmbedding, k: 50)
        ->filter(Filter::term('tag', 'vectors')),
);
```

`k` (at most 10,000) is how many nearest neighbours to retrieve. Filters apply while the neighbours are gathered,
not after, and facets count within the neighbours. Orion does not compute embeddings: bring them from your model.
Every engine reports `Capability::Vectors`; Meilisearch ranks by cosine similarity only.

## Hybrid search and fusion

A query with both lexical clauses and a vector is hybrid. Lexical and vector scores live on incompatible scales, so
Orion combines the two rankings by rank, not by score, with reciprocal rank fusion:

```php
use Orion\Query\Fusion;

$results = $graph->searcher()->search(
    Query::on('articles')
        ->match('title', 'search')
        ->vector('embedding', $queryEmbedding, k: 10)
        ->fuse(Fusion::reciprocalRank(k: 60)),       // the default when no fusion is given
);

$results->fusion?->toArray();
// ['strategy' => 'reciprocal_rank', 'k' => 60, 'contributions' => [
//     ['id' => 'a1', 'source' => 'lexical', 'rank' => 1, 'score' => 0.0164], ...]]
```

No engine adapter reports native hybrid fusion (`Capability::HybridFusion`), so Orion fuses in core on every
engine: it runs the lexical leg and the vector leg as two searches on the same tenant-scoped query and merges them.
What follows from that:

- Each hit's score is its fused score; `$results->totalIsEstimate` is true (it counts the fused candidates).
- Facets and highlights come from the lexical leg.
- A hybrid query can only sort by `_score` (`Direction::Asc` reverses the fused ranking); another sort throws.
- The two legs read at most 1,000 hits each, so `page × size` must stay within 1,000.
- `Fusion::weighted(lexical: 0.3, vector: 0.7)` exists in the model but needs an engine with native fusion, which
  none has: it throws `UnsupportedClause`.

The reasoning is in [spec §5.1](../spec/orion-spec.md).

## See the native query without running it

`compile()` takes the same path as `search()` (tenant, schema binding, page window, tenant filter) and returns
what the engine would receive, without sending it:

```php
$compilation = $graph->searcher()->compile(Query::on('docs')->match('title', 'guide')->facet('tag'));

$compilation->engine;   // 'solr'
$compilation->index;    // the physical index, e.g. docs_v1
$compilation->query;    // the query after binding and scoping (schema boosts applied)
$compilation->native;   // the engine's request, for a single-request query
$compilation->legs;     // ['lexical' => ..., 'vector' => ...] when core fuses a hybrid query
$compilation->fusion;   // the fusion that would combine the legs
```

On Solr the native form is the request path and parameters, for example:

```json
{"path": "docs_v1/select", "params": {"q": "{!dismax qf=$o1 mm=1 v=$o2}", "fq": ["-orion_tombstone:true"],
 "fl": "id,orion_source,score", "start": "0", "rows": "20", "facet": "true", "facet.mincount": "1",
 "facet.field": ["tag"], "f.tag.facet.limit": "10", "o1": "title", "o2": "guide"}}
```

Elasticsearch and OpenSearch return the client's `index` and `body`, Meilisearch its search request, and the
in-memory engine the neutral query itself. Every engine Orion ships can compile; an adapter that cannot throws a
`LogicException`.

## Queries as data

`Query::fromArray()` builds the same query from plain data, for JSON APIs and agents; `toArray()` is its inverse:

```php
$query = Query::fromArray([
    'index' => 'products',
    'query' => [['type' => 'match', 'fields' => ['title' => 2.0, 'description' => 1.0], 'value' => 'trail shoe']],
    'filters' => [
        ['type' => 'range', 'field' => 'price', 'lte' => 100],
        ['type' => 'bool', 'should' => [['type' => 'term', 'field' => 'brand', 'value' => 'Acme']]],
    ],
    'sort' => [['field' => 'price', 'direction' => 'asc']],
    'facets' => [['field' => 'brand', 'size' => 10]],
    'highlight' => ['title'],
    'page' => ['page' => 1, 'size' => 20],
]);
```

Optional keys are `vector` (`field`, `vector`, `k`) and `fusion` (`strategy`: `reciprocal_rank` with `k`, or
`weighted` with `lexical` and `vector`). A clause takes `field` with an optional `boost`, or `fields`. Unknown keys
are refused, and lists are capped (64 clauses, 64 filters, 8 sorts, 16 facets, 16 highlights) so one request cannot
build an arbitrarily large query.

The same form is what the agent tools take: `Orion\Tools\Tools::for($graph)` returns `compile_query`, `search` and
six more framework-free tools that a host can expose over MCP (see [Frameworks](frameworks.md#univeros)).

## The native escape hatch

For what the neutral query does not cover (function scores, engine-specific query parsers, geo queries), pass a
callable that builds the engine's own query:

```php
use Orion\IndexName;

// Elasticsearch or OpenSearch: a search body
$results = $graph->searcher()->raw(IndexName::of('products'), fn(): array => [
    'query' => ['function_score' => ['query' => ['match' => ['title' => 'shoe']], 'field_value_factor' => ['field' => 'price']]],
]);

// Solr: request parameters
$results = $graph->searcher()->raw(IndexName::of('docs'), fn(): array => ['q' => 'title:guide']);

// Meilisearch: a search request, where `q` with the engine's own typo tolerance is available
$results = $graph->searcher()->raw(IndexName::of('products'), fn(): array => ['q' => 'shoe']);

// In-memory: a predicate over each stored document
$results = $graph->searcher()->raw(IndexName::of('articles'), fn(array $source): bool => ($source['tag'] ?? null) === 'vectors');
```

The result is the same `ResultSet`. The query is not checked against the schema, but the tenant filter still applies:
Orion ANDs it into the native query. Under multi-tenancy each adapter also refuses native shapes that could read
across tenants (aggregations, suggesters, Solr local parameters and joins, OpenSearch search pipelines and the
like); with a single tenant the full native query language is available. Each package README lists its rules.

## Errors

| Exception | When |
| --- | --- |
| `InvalidArgumentException` | The query names an undeclared field, uses a field for what its flags do not allow, carries a value of the wrong type, or the index has not been built |
| `Orion\Adapter\UnsupportedClause` | A page past the offset window, a hybrid page past the fusion window, weighted fusion, or a clause the engine lacks the capability for (`$e->capability`) |
| `Orion\Tenancy\NoTenant` | Under a tenant resolver, the current context has no tenant |
| `LogicException` | `compile()` on an engine that cannot show its native query |

The PSR-15 endpoints turn these into RFC 9457 problems (400, 401, 500); see [Frameworks](frameworks.md).
