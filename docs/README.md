# Orion documentation

Everything about Orion, the deterministic index lifecycle and hybrid search layer for PHP: how to use it, what each
package contains, and why it is designed the way it is. New to Orion? Start with
[Getting started](guides/getting-started.md).

## Guides

How to do things with Orion, task by task. The [guides overview](guides/README.md) has the recommended reading order.

- [Getting started](guides/getting-started.md): install, a first index, a first search.
- [Index schemas](guides/index-schemas.md): schema as code and as `search:` manifests.
- [Indexing](guides/indexing.md): the transactional outbox, the drain, dead letters, projectors.
- [Searching](guides/searching.md): the query model, vector and hybrid search, compiling, the escape hatch.
- [Index lifecycle](guides/index-lifecycle.md): plan, apply, zero-downtime reindex, prune, receipts.
- [Engines](guides/engines.md): connecting each engine, and the capability matrix.
- [Multi-tenancy](guides/multi-tenancy.md): shared indices, Polaris, your own resolver.
- [CLI reference](guides/cli.md): every `bin/orion` command.
- [Testing](guides/testing.md): testing an application that uses Orion.
- [Frameworks](guides/frameworks.md): PSR-15 endpoints and the Univeros module.

## Packages

What each package is, how to install it, and a minimal example.

| Package | README |
| --- | --- |
| `orion-search/core` | [packages/core](../packages/core/README.md) |
| `orion-search/testing` | [packages/testing](../packages/testing/README.md) |
| `orion-search/pdo` | [packages/pdo](../packages/pdo/README.md) |
| `orion-search/cli` | [packages/cli](../packages/cli/README.md) |
| `orion-search/elasticsearch` | [packages/elasticsearch](../packages/elasticsearch/README.md) |
| `orion-search/opensearch` | [packages/opensearch](../packages/opensearch/README.md) |
| `orion-search/meilisearch` | [packages/meilisearch](../packages/meilisearch/README.md) |
| `orion-search/solr` | [packages/solr](../packages/solr/README.md) |
| `orion-search/psr15` | [packages/psr15](../packages/psr15/README.md) |
| `orion-search/polaris` | [packages/polaris](../packages/polaris/README.md) |
| `univeros/orion` | [packages/univeros](../packages/univeros/README.md) |

## Specification

Why Orion is built the way it is. The guides link to the relevant sections.

- [`spec/orion-spec.md`](spec/orion-spec.md): the product specification (v1, June 2026): positioning, principles,
  query and schema models, adapter contracts, lifecycle, tenancy, receipts, CLI, MCP, capability matrix,
  determinism boundary, verification regime.
- [`spec/revision.md`](spec/revision.md): the framework-agnostic re-cut (v2 delta, October 2026): repository shape,
  package map, ports, the same-connection invariant, the Univeros module, core layout, work packages. Where it
  disagrees with v1, it wins.

The specification records the design as it was planned. Where it and the code differ, the code and the guides
describe what was released; the [changelog](../CHANGELOG.md) lists what changed.

## Decisions

- [`decisions.md`](decisions.md): the append-only decisions log, one entry per choice with its reason and what was
  rejected.

The agent skill is `.ai/skills/orion/SKILL.md`, and [`AGENT.md`](../AGENT.md) orients coding agents.
