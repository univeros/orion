# orion-search/solr

Orion's engine adapter for SolrCloud 9.x and 10.x, on `solarium/solarium` over cURL.

```php
use Orion\Registry\EngineRegistry;
use Orion\Solr\SolrEngine;

$engines = new EngineRegistry(solr: SolrEngine::connect('http://localhost:8983/solr'));
// or with your own client: new SolrEngine($solariumClient)
```

- SolrCloud only: the adapter uses the Collections, Configsets and Schema APIs and collection aliases. Standalone
  Solr is not supported.
- No configset to install. Orion ships one (`configset/`: `solrconfig.xml` and `managed-schema.xml`) and uploads it
  through the Configsets API when it creates a collection, under a name carrying a hash of its files, so a changed
  configset is a new name and collections built from an older one keep theirs. Each collection gets its own copy
  (`orion.<collection>`), so the fields Orion adds to one never reach another. A cluster that restricts the
  Configsets API must let Orion's user upload and create configsets.
- One collection per physical index, behind a collection alias. Declared fields are added through the Schema API,
  with field types from the configset: a stemming type per language hint, ASCII folding where declared.
- Writes are versioned by the outbox offset and tolerant per document; deletes leave tombstones that searches
  exclude. Writes become searchable after the configset's one-second soft commit; `flush()` commits at once.
- Vectors are `DenseVectorField`s with the declared similarity; hybrid fusion is core's. No nested fields.
- `IndexSchema::native('solr', ['collection' => [...]])` sets collection creation parameters: `numShards`,
  `replicationFactor`, `nrtReplicas`, `tlogReplicas`, `pullReplicas`, as numbers. Nothing else is accepted.
- `raw()` takes `callable(): array` returning request parameters (`['q' => '...', 'fq' => [...]]`); the envelope is
  the same. Under a tenant scope a request is held to what can be checked: Lucene, dismax or edismax syntax with no
  local parameters, nested queries or magic fields, no function reading collection-wide statistics, and only
  parameters that act within the matched documents, named exactly. Facets, stats, terms, joins and other
  collections are refused there. Single tenancy keeps the full escape hatch.
- `SolrEngine::connect($url, timeout: 60.0)` takes a node URL (`http://host:8983/solr`) and a request timeout in
  seconds.

Proven by `Orion\Testing\EngineConformanceTestCase` against SolrCloud 10.0 and 9.11, with the tenancy isolation,
determinism, fault-injection, query-equivalence and endpoint-fixture suites on both. Connecting it and how it
compares with the other engines: the [Engines guide](../../docs/guides/engines.md#solr).
