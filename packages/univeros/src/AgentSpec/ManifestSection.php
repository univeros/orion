<?php

declare(strict_types=1);

namespace Univeros\Orion\AgentSpec;

use Orion\Manifest\Manifest;
use Orion\Schema\Field;

use function array_map;
use function implode;
use function sprintf;

/**
 * Orion's section of `bin/altair manifest:generate` (`.agent/modules/univeros/orion.md`): the declared indices, the
 * commands and the doctor checks, from the manifests alone, so generating it needs no database or engine. Byte-stable
 * for the same manifests.
 */
final class ManifestSection
{
    public static function render(Manifest $manifest): string
    {
        $lines = ['# Orion', '', 'Deterministic index lifecycle on the application\'s Cycle connection: plan, apply, zero-downtime reindex by outbox replay, signed receipts.', '', '## Indices', ''];
        foreach ($manifest->declarations() as $declaration) {
            $schema = $declaration->schema;
            $endpoint = $declaration->endpoint;
            $lines[] = sprintf(
                '- `%s` on %s%s: %s%s',
                $schema->index->value,
                $declaration->engine ?? 'the configured engine',
                $schema->orgKey === null ? '' : sprintf(', org key `%s`', $schema->orgKey),
                implode(', ', array_map(static fn(Field $field): string => sprintf('`%s` %s', $field->name, $field->type->value), $schema->fields)),
                $endpoint === null ? '' : sprintf('; served at `%s %s`%s', $endpoint->method, $endpoint->path, $endpoint->permission === null ? '' : sprintf(' (permission `%s`)', $endpoint->permission)),
            );
        }
        if ($manifest->declarations() === []) {
            $lines[] = '- none declared (add `api/**/search.yaml`)';
        }

        return implode("\n", [...$lines, '', '## Commands', '',
            '- `bin/altair orion:plan <index>` diffs the declaration against the index; `orion:apply <index>` applies additive changes with a receipt and exits 3 on breaking ones.',
            '- `bin/altair orion:reindex <index>` rebuilds by replay and swaps the alias; run it again to resume, `--abort` to give up.',
            '- `bin/altair orion:outbox:drain`, `orion:outbox:retry <index>`, `orion:prune <index>`, `orion:status <index>`, `orion:drift --check`, `orion:verify`.',
            '- Every command takes `--json` (canonical, deterministic) and mutating ones `--dry-run`.',
            '',
            '## Doctor',
            '',
            '`orion_engine_reachable`, `orion_registry_current` (fixable), `orion_schema_drift` (additive fixable, breaking needs a reindex), `orion_alias_resolves`, `orion_outbox_lag`, `orion_dead_letters`, `orion_receipts_verify`.',
            '',
        ]);
    }
}
