# orion-search/cli

`bin/orion`, Orion's command line: plan, apply, reindex, prune, drift, status, flush, verify, capabilities, the
outbox drain and retry, and receipt key generation, run against your application's own storage and engines, with
output as YAML for people or canonical JSON for scripts and agents.

```sh
composer require orion-search/cli
```

```php
<?php
// orion.php: what bin/orion runs against
require __DIR__ . '/vendor/autoload.php';

return new Orion\Wiring\Config(
    storage: new Orion\Pdo\PdoStorage($pdo),
    engines: new Orion\Registry\EngineRegistry(elasticsearch: Orion\Elasticsearch\ElasticsearchEngine::connect($url)),
    tenancy: Orion\Tenancy\Tenancy::single(),
);
```

```sh
vendor/bin/orion apply products --bootstrap=orion.php
ORION_BOOTSTRAP=orion.php vendor/bin/orion outbox:drain --json
```

- The bootstrap file returns your `Orion\Wiring\Config` or `Orion\Orion`; pass it with `--bootstrap` (`-b`) or
  `ORION_BOOTSTRAP`. `receipts:keygen` needs none.
- A schema comes from the index's `search:` manifest, or from `--schema=<file>` (a manifest or the schema's array
  form, YAML or JSON). The engine defaults to the one that built the index, then the manifest's, then the only one
  configured; `--engine` overrides it.
- Every mutating command takes `--dry-run` and prints what it would do.
- Exit codes: 0 done; 1 failed, with the error printed as data (`error.type`, `error.message`); 3 action required
  (`apply` found a breaking change that needs a reindex, `drift --check` found drift).
- `--json` output is canonical: keys sorted at every depth, so the same state prints the same bytes.
- A host can add the commands to its own console: `Orion\Cli\Application::commands(new Orion\Cli\Context($orion))`
  returns them all. The Univeros module does this, as `bin/altair orion:*`.

| Command | Does |
| --- | --- |
| `plan <index>` | Diff the declared schema against the live index and classify every change |
| `apply <index>` | Create the index or apply additive changes in place; refuse breaking ones |
| `reindex <index>` | Rebuild from the outbox into the next version and swap the alias; `--abort` gives up an interrupted one |
| `prune <index>` | Drop replaced versions after the grace window (`--grace`, 300 s) |
| `drift` | Report drift between declared schemas and their indices; `--check` exits 3 on any |
| `status <index>` | Versions, alias, outbox lag, dead letters, pending reindex, receipts, capabilities |
| `flush <index>` | Refresh or commit the index behind the alias |
| `verify` | Verify the receipt chain; `--head=<sequence>:<hash>` anchors it |
| `capabilities [<engine>]` | Print the capability matrix |
| `outbox:drain [<index>]` | Write outbox entries to their indices and report lag |
| `outbox:retry <index>` | Resend an index's dead letters |
| `receipts:keygen` | Generate an Ed25519 key pair (`--kid`) |

Every command, with its options and output, is in the [CLI reference](../../docs/guides/cli.md).
