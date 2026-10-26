# Orion on Univeros: the demo

The Univeros skeleton with `univeros/orion` on SQLite and Elasticsearch.

- `.env` names the engine (`ORION_ENGINE=elasticsearch`, `ORION_ELASTICSEARCH_URL`); the module builds it.
- `app/AppModule.php` adds the product routes.
- `app/Products/ProductsMiddleware.php` serves the product routes. A write saves the product and its outbox entry in
  one transaction on the application's own connection. A search reads whatever version the alias points at.
- `api/products/search.yaml` declares the index.

```sh
docker run -d -p 9200:9200 -e discovery.type=single-node -e xpack.security.enabled=false elasticsearch:9.5.5
composer install
bin/setup            # .env with a new receipt key, db:migrate, orion:apply products
bin/walkthrough.sh   # write, search, delete, breaking change, reindex while writing, prune, verify, doctor
```

The walkthrough runs these steps:
- **Writes.** It writes three products and searches them.
- **A delete.** It deletes one, as a versioned tombstone.
- **A breaking change.** It changes the title's analysis in the manifest, so `orion:drift --check` and `orion:apply`
  exit 3.
- **A reindex while writing.** It writes another product while version 1 still serves, then reindexes by replay. The
  search then finds every product on version 2.
- **Cleanup.** It prunes version 1.
- **Checks.** It verifies the receipt chain and requires all seven Orion doctor checks to be ok.

To run it again from scratch:

```sh
rm -f .env var/orion.sqlite
curl -X DELETE 'localhost:9200/products_v*'
```
