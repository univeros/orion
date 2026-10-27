# Engines

Connect Orion to Elasticsearch, OpenSearch, Meilisearch or Solr, and know what each one supports before you rely
on it. Every engine adapter passes the same conformance suite, so the neutral query means the same everywhere; this
page lists where engines differ and what Orion does about it.

## Register one or more engines

An `EngineRegistry` holds the adapters by a name you choose:

```php
use Orion\Registry\EngineRegistry;

$engines = new EngineRegistry(
    search: ElasticsearchEngine::connect('http://localhost:9200'),
    catalog: MeilisearchEngine::connect('http://localhost:7700', $apiKey),
);
```

An index lives on one engine. The lifecycle services take the engine's name (`$graph->planner('search')`,
`$graph->reindexer('catalog')`), a manifest can name it (`engine: catalog`), and `bin/orion` takes `--engine`
(defaulting to the engine that built the index, then the manifest's, then the only one configured). Searching and
draining need no name: Orion remembers which engine built each index.

## Elasticsearch

Elasticsearch 8.19 and 9.x, on the official client. Install the client major that matches your server: client 9
cannot talk to an 8.x cluster.

```sh
composer require orion-search/elasticsearch
composer require elasticsearch/elasticsearch:^8.19    # only for an 8.x server
```

```php
use Elastic\Elasticsearch\ClientBuilder;
use Orion\Elasticsearch\ElasticsearchEngine;

$engine = ElasticsearchEngine::connect('http://localhost:9200');                       // one or more hosts
$engine = new ElasticsearchEngine(ClientBuilder::create()->setHosts([$url])->build());  // your own client (auth, TLS)
```

- Mappings are `dynamic: strict`: a document with an undeclared field fails alone instead of growing the mapping.
- `integer` maps to `long`, `float` to `double`. A field whose native type has no neutral equivalent (created by
  hand, say) reads back as unknown and makes any plan touching it breaking.
- Writes and deletes are externally versioned with the outbox offset.
- `native('elasticsearch', ['settings' => [...], 'mappings' => [...]])` merges over the generated create body.
- Native hybrid fusion (the RRF retriever) needs a paid licence, so Orion fuses hybrid queries in core.
- Adding a field that needs an analyzer the live index lacks is refused in place; Orion reports a reindex.

Tested against 9.5 and 8.19. [Package README](../../packages/elasticsearch/README.md).

## OpenSearch

OpenSearch 2.19 and 3.x, on `opensearch-project/opensearch-php` (one client major for both lines) over Guzzle.

```sh
composer require orion-search/opensearch
```

```php
use OpenSearch\GuzzleClientFactory;
use Orion\OpenSearch\OpenSearchEngine;

$engine = OpenSearchEngine::connect('http://localhost:9200');                                       // one node URL
$engine = new OpenSearchEngine((new GuzzleClientFactory())->create(['base_uri' => $url]));          // your own client
```

- Strict mappings, `long` and `double`, external versions: as on Elasticsearch.
- A vector field is a `knn_vector` on the Lucene engine (HNSW, the metric as its space type) and turns
  `index.knn` on for the index. Adding a vector field to an index created without one needs a reindex.
- `native('opensearch', ['settings' => [...], 'mappings' => [...]])` merges over the generated create body.
- Native hybrid search needs a search pipeline whose score normalisation is not Orion's, so Orion fuses in core.
- AWS-signed clients are not built in: build the client yourself and pass it.

Tested against 3.9 and 2.19. [Package README](../../packages/opensearch/README.md).

## Meilisearch

Meilisearch 1.13 and later, on `meilisearch/meilisearch-php` over Guzzle.

```sh
composer require orion-search/meilisearch
```

```php
use Orion\Meilisearch\MeilisearchEngine;

$engine = MeilisearchEngine::connect('http://localhost:7700', $apiKey);   // the key is optional
$engine = new MeilisearchEngine($url, $apiKey, $psr18HttpClient);       // your own HTTP client
```

Meilisearch is the engine whose model differs most from Orion's, and the adapter is explicit about the cost:

- **Lexical clauses run as filters, not as `q`.** Meilisearch's `q` requires every word (or drops some), tolerates
  typos and completes the last word, none of which is the portable `match`. Orion matches on token attributes it
  maintains itself, so a lexical-only query finds the right documents but is **not ranked by relevance**: hits come
  in stored order unless you sort. `q`, with your own typo, synonym and ranking settings, stays available through
  the escape hatch (`raw()`).
- Text is lowercased and split into words; the `language` and `folding` hints are recorded but not applied.
- Vectors are user-provided embeddings ranked by cosine similarity, the only metric available; another metric is
  refused when the index is created. Adding a vector field to a live index needs a reindex.
- No native aliases: Orion keeps the alias in its own table. No nested fields. One geo field per index. A `prefix`
  clause on a number is refused.
- Every write is a task, which Orion waits for. Versions are kept by Orion (read, then write), so the outbox must
  be the only writer of an index, which it is when you use it.
- Orion turns on the `containsFilter` experimental feature when it creates an index. A Meilisearch that refuses it
  (Meilisearch Cloud among them) cannot serve Orion's queries. The API key needs the `indexes.*`, `documents.*`,
  `settings.*`, `tasks.get`, `search` and `experimental.*` actions.
- `native('meilisearch', ['settings' => [...]])` merges over the generated settings, except what Orion owns.
- On 1.13, vector search returns what the engine ranks nearest, not provably the k nearest; the equivalence suite
  runs on the current line only.

Tested against 1.56 and 1.13. [Package README](../../packages/meilisearch/README.md).

## Solr

SolrCloud 9.x and 10.x, on Solarium over cURL. Standalone (non-cloud) Solr is not supported: Orion needs the
Collections, Configsets and Schema APIs and collection aliases.

```sh
composer require orion-search/solr
```

```php
use Orion\Solr\SolrEngine;

$engine = SolrEngine::connect('http://localhost:8983/solr');               // a SolrCloud node; timeout 60 s by default
$engine = SolrEngine::connect('http://localhost:8983/solr', timeout: 120.0);
$engine = new SolrEngine($solariumClient);                                 // your own Solarium\Client
```

- **No configset to install.** Orion ships its configset (`packages/solr/configset/`) and uploads it through the
  Configsets API when it creates a collection, under a name carrying a hash of its files. Each collection gets its
  own copy (`orion.<collection>`), so fields Orion adds to one collection never reach another. If your cluster
  restricts the Configsets API, the user Orion connects as needs permission to upload and create configsets.
- One collection per index version, behind a collection alias. Declared fields are added with the Schema API;
  language hints map to stemming field types in the configset.
- Writes are versioned by the outbox offset; deletes leave tombstones that searches exclude.
- No nested fields. `native('solr', ['collection' => ['numShards' => 2, 'replicationFactor' => 2]])` sets
  collection creation parameters (`numShards`, `replicationFactor`, `nrtReplicas`, `tlogReplicas`,
  `pullReplicas`).
- `raw()` takes request parameters (`['q' => '...', 'fq' => [...]]`). Under multi-tenancy only plain Lucene,
  dismax or edismax syntax and parameters that act within the matched documents are allowed.
- Writes become searchable after the configset's one-second soft commit; `bin/orion flush` commits at once.

Tested against 10.0 and 9.11. [Package README](../../packages/solr/README.md).

## The in-memory engine

`Orion\Adapter\InMemory\InMemoryEngine`, in `orion-search/core`, implements every adapter verb over PHP arrays and
passes the conformance suite. Use it in development and tests ([Testing](testing.md)). Its indices live in the
process; it lowercases ASCII only, does not stem, and has no aliases or nested fields. `raw()` takes
`callable(array $source): bool`.

## Capability matrix

What each adapter reports through `supports(Capability $capability)`, and what Orion does where the answer is no.
`bin/orion capabilities` prints the same table for your configured engines.

| Capability | Elasticsearch | OpenSearch | Solr | Meilisearch | In-memory | When unsupported |
| --- | --- | --- | --- | --- | --- | --- |
| `Vectors` | yes | yes | yes | yes | yes | A vector field or clause is refused with `UnsupportedClause` |
| `HybridFusion` (native) | no | no | no | no | no | Orion fuses the two legs in core with reciprocal rank; weighted fusion is refused |
| `NestedDocuments` | yes | yes | no | no | no | A `nested` field is refused when the index is created |
| `Facets` | yes | yes | yes | yes | yes | A facet is refused |
| `Highlighting` | yes | yes | yes | yes | yes | A highlight is refused |
| `NativeAliases` | yes | yes | yes | no | no | Orion keeps the alias in its own table; the swap is still atomic |
| `AsyncWrites` | no | no | no | yes | no | (yes means writes are tasks; Orion waits for them) |
| `CursorPagination` | yes | yes | yes | no | no | Not used by the neutral query yet: offset paging up to result 10,000 everywhere |
| `FunctionScore` | yes | yes | yes | no | no | Not in the neutral query; use the escape hatch where the engine has it |
| `Streaming` | no | no | no | no | no | Not used |
| `OptimisticConcurrency` | yes | yes | yes | yes | yes | Required for lossless replay; every shipped adapter has it (Meilisearch through Orion's own version check) |

Across every engine the neutral query (match, term, prefix, phrase, filters, sort, facets, highlight, offset
paging, k-NN, hybrid) is supported and means the same thing; the native escape hatch is available with the same
result envelope. Where a capability is missing, Orion either supplies the behaviour in core or refuses with a typed
`Orion\Adapter\UnsupportedClause` (which carries `capability`, `clause` and `engine`), never a silently different
answer. The design behind this table is [spec §16](../spec/orion-spec.md); this table is what the code reports
today, and where the two differ, this one is right.

## Configuring engines in the Univeros module

The module builds one engine from the environment: `ORION_ENGINE=elasticsearch` with `ORION_ELASTICSEARCH_URL`
(comma-separated hosts), `opensearch` with `ORION_OPENSEARCH_URL`, `solr` with `ORION_SOLR_URL`, `meilisearch` with
`ORION_MEILISEARCH_URL` and `ORION_MEILISEARCH_KEY`, or `memory`. Bind an `EngineRegistry` in the container for
several engines or a client you build yourself. See [Frameworks](frameworks.md#univeros).

## Writing an adapter

An engine adapter implements `SearchAdapter`, `SchemaAdapter` and `WriteAdapter` (and `QueryCompiling` for
`compile()`), reports its capabilities, and is done when it passes `Orion\Testing\EngineConformanceTestCase`.
Orchestration (diffing, reindex, alias swap, replay, fusion) belongs in core, not in the adapter. The contracts are
in [spec §7](../spec/orion-spec.md) and the existing adapters under `packages/` are the reference.
