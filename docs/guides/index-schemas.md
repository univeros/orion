# Index schemas

Declare an index as code: its fields, their types and what each may be used for, the tenant field, and anything
engine-specific. The same schema can be written in PHP or as a YAML manifest, and Orion diffs it against the live
index to decide whether a change applies in place or needs a reindex.

## Build a schema in PHP

```php
use Orion\Schema\Field;
use Orion\Schema\IndexSchema;
use Orion\Schema\VectorMetric;

$schema = IndexSchema::for('products')
    ->text('title', language: 'en', boost: 3.0)
    ->text('description', language: 'en', folding: true)
    ->keyword('sku', filterable: true, sortable: true)
    ->keyword('brand', filterable: true, facetable: true)
    ->float('price', filterable: true, sortable: true, facetable: true)
    ->integer('stock', filterable: true, sortable: true)
    ->boolean('in_stock', filterable: true)
    ->date('published_at', filterable: true, sortable: true)
    ->vector('embedding', dims: 384, metric: VectorMetric::Cosine)
    ->object('dimensions', Field::float('width', filterable: true), Field::float('height'));
```

`IndexSchema` is immutable: every call returns a copy, so a base schema can be shared and extended safely. A field
declared twice, an unknown language code, a non-positive boost or dimension count all throw an
`InvalidArgumentException` at the call that caused them.

Names: an index name is lowercase ASCII letters, digits, `_` and `-`, starting with a letter or digit, at most 255
bytes (the strictest rules of the four engines, intersected). A field name is letters, digits and `_`, starting with
a letter. Queries reach sub-fields of an object by dotted path (`dimensions.width`).

## Field types

| Builder | Options | Use it for |
| --- | --- | --- |
| `text($name, language, boost, folding)` | `language`: ISO 639 code (`en`, `pt-br`); `boost`: query-time weight, default 1.0; `folding`: fold accents and case | Analysed full text: `match`, `phrase`, `prefix`, highlighting |
| `keyword($name, filterable, sortable, facetable)` | flags | Exact strings: ids, tags, brands |
| `integer(...)`, `float(...)`, `date(...)` | `filterable`, `sortable`, `facetable` | Numbers and ISO 8601 dates |
| `boolean($name, filterable, facetable)` | flags | Flags such as `in_stock` |
| `geo($name, filterable, sortable)` | flags | A point, written as `['lat' => 52.37, 'lon' => 4.89]` |
| `vector($name, dims, metric)` | `dims`: 1 to 4096; `metric`: `VectorMetric::Cosine` (default), `DotProduct`, `Euclidean` | Embeddings for k-NN and hybrid search |
| `object($name, Field ...$fields)` | sub-fields | Structured values, flattened into dotted paths |
| `nested($name, Field ...$fields)` | sub-fields | Arrays of objects matched as separate documents; Elasticsearch and OpenSearch only |

Objects and nested fields nest at most 10 levels. For a sub-field, use the `Field` factories (`Field::text()`,
`Field::keyword()` and so on), which take the same arguments as the builder methods.

Orion does not invent defaults: a field is only filterable, sortable or facetable when you say so, and the query
binder enforces it (next section).

**Geo is declared and stored, not yet queried.** The neutral query has no geo distance or bounding-box filter and
no sort by distance from a point, so a geo field is only useful through the engine's native query, the escape
hatch ([Searching](searching.md#the-native-escape-hatch)). Meilisearch holds one geo field per index.

## What each flag allows

Every query is checked against the schema the index was registered with before it reaches the engine. The rules:

| Query part | Field must be |
| --- | --- |
| `match`, `phrase`, `prefix` | `text` or `keyword` |
| `term` clause | `keyword`, `integer`, `float`, `boolean` or `date`; never analysed text |
| `Filter::term()`, `Filter::terms()` | `filterable`, of type `keyword`, `integer`, `float`, `boolean` or `date` |
| `Filter::range()` | `filterable`, of type `keyword`, `integer`, `float` or `date` |
| `Filter::exists()` | declared |
| `sort()` | `sortable`, or `_score` |
| `facet()` | `facetable` |
| `highlight()` | `text` |
| `vector()` | `vector`, with exactly the declared number of dimensions |

Values must fit the type too: an integer field takes whole numbers, a date field ISO 8601 strings that are real
calendar dates (`2026-10-27`, `2026-10-27T09:30:00Z`), a boolean field booleans. A violation throws an
`InvalidArgumentException` naming the field, for example
`The field "views" of "articles" has type integer; "many" is not a whole number.`

## Languages and folding

`language` and `folding` are the only analysis Orion models, because they are the only analysis that means the
same on every engine. How each engine honours them:

| Engine | `language` | `folding` |
| --- | --- | --- |
| Elasticsearch, OpenSearch | The built-in language analyzer | Lowercase and ASCII folding in an Orion-named analyzer |
| Solr | A language field type from Orion's configset (stemmer per language) | ASCII folding in the field type |
| Meilisearch, in-memory | Recorded, but text is only lowercased and split on non-letters: no stemming | Recorded, not applied |

The languages Elasticsearch, OpenSearch and Solr accept: `ar`, `bg`, `ca`, `cs`, `da`, `de`, `el`, `en`, `es`,
`eu`, `fa`, `fi`, `fr`, `ga`, `gl`, `hi`, `hu`, `hy`, `id`, `it`, `lt`, `lv`, `nl`, `no`, `pt`, `pt-br`, `ro`, `ru`,
`sv`, `th`, `tr`. Another code passes the schema builder but is refused by those engines when the index is created.

The in-memory engine lowercases ASCII only, so `match` on non-ASCII text can differ from a real engine.

Anything past a language and folding (synonyms, n-grams, custom tokenizers) is engine-specific: put it in
`native()`, below.

## Boost is a query-time weight

`boost` multiplies a text field's weight in every `match`, `phrase` and `prefix` that reads it. No engine stores it
in the index, so changing a boost never needs a reindex: `apply()` records the new declaration and queries use it
from then on. A query can add its own weights on top (`->match(['title' => 2.0, 'description' => 1.0], ...)`).

## The org key (multi-tenant indices)

```php
$schema = IndexSchema::for('notes')->text('body')->orgKey('org_id');
```

`orgKey()` names the field that holds the tenant. If the schema does not declare that field, it is added as a
filterable keyword; if it does, it must already be one. Under `Tenancy::resolvedBy()` every index must have an
org key, and under `Tenancy::single()` none may. See [Multi-tenancy](multi-tenancy.md).

## Engine-specific settings

`native($engine, $settings)` is the escape hatch for index settings, as plain data so it can be hashed, diffed and
loaded from YAML. It applies only when the index lives on that engine, and calling it again for the same engine
replaces the earlier settings.

```php
$schema = $schema
    ->native('elasticsearch', ['settings' => ['number_of_shards' => 2, 'number_of_replicas' => 1]])
    ->native('solr', ['collection' => ['numShards' => 2, 'replicationFactor' => 1]]);
```

| Engine | Accepted keys |
| --- | --- |
| `elasticsearch`, `opensearch` | `settings` and `mappings`, merged over the generated create-index body; `dynamic: strict` and Orion's `_meta` cannot be overridden |
| `meilisearch` | `settings`, merged over the generated settings, except what Orion owns (filterable, sortable and displayed attributes, embedders, pagination, faceting, distinct) |
| `solr` | `collection` with `numShards`, `replicationFactor`, `nrtReplicas`, `tlogReplicas`, `pullReplicas`, as numbers |

The engine name here is the adapter's own name (`elasticsearch`, `opensearch`, `meilisearch`, `solr`), not the
name you registered it under. Any change to native settings is a breaking change: it needs a reindex.

## Declare it in YAML: the `search:` manifest

The same schema as a manifest, in `api/products/search.yaml`:

```yaml
index: products
engine: elasticsearch          # optional: the engine to build it on when several are configured
schema:
  title:        { type: text, language: en, boost: 3.0 }
  description:  { type: text, language: en, folding: true }
  sku:          { type: keyword, filterable: true, sortable: true }
  brand:        { type: keyword, filterable: true, facetable: true }
  price:        { type: float, filterable: true, sortable: true, facetable: true }
  in_stock:     { type: boolean, filterable: true }
  published_at: { type: date, filterable: true, sortable: true }
  embedding:    { type: vector, dims: 384, metric: cosine }
  dimensions:
    type: object
    fields:
      width:  { type: float, filterable: true }
      height: { type: float }
org_key: org_id                # optional
native:                        # optional, by engine
  elasticsearch: { settings: { number_of_shards: 2 } }
```

Orion loads every `search.yaml` and `*.search.yaml` (or `.yml`) under the manifest directories: `api/` by
default, or the list in `Config::$manifestDirectories`. Unknown keys, invalid fields and an index declared in two
files are refused with the file named. The manifest can also declare where documents come from (`source`, see
[Indexing](indexing.md#projections-entity-to-document)) and a search endpoint (`search`, see
[Frameworks](frameworks.md#the-search-endpoints-over-psr-15)).

```php
$declaration = $graph->manifest()->find('products');   // ?Orion\Manifest\Declaration
$graph->planner('elasticsearch')->apply($declaration->schema);
```

`bin/orion apply products` reads the manifest by itself; `--schema=<file>` takes a manifest or the schema's own
array form instead.

## The array form

`IndexSchema::toArray()` and `IndexSchema::fromArray()` convert between the AST and plain data, for storage, JSON
files and tools:

```php
$data = $schema->toArray();   // ['index' => 'products', 'fields' => [...], 'org_key' => null, 'native' => []]
$same = IndexSchema::fromArray($data);
$same->hash() === $schema->hash();   // true
```

`hash()` identifies a declaration whatever order it was written in. It is recorded with each index version and in
every receipt.

## What a change costs

Adding a field is additive and applies to the live index in place. Everything else (removing a field, changing a
type, language, folding, flag or vector shape, the org key or native settings) is breaking and needs a reindex. A
change Orion cannot classify is treated as breaking. Some engines refuse certain additive changes too
(Elasticsearch cannot add an analyzer the live index lacks; Meilisearch and OpenSearch cannot add a vector field in
place), and Orion then reports a reindex as required. [Index lifecycle](index-lifecycle.md) shows the workflow; the
reasoning is in [spec §6 and §8.1](../spec/orion-spec.md).
