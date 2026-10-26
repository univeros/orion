<?php

declare(strict_types=1);

namespace Univeros\Orion\Doctor;

use Altair\Doctor\Contracts\CheckInterface;
use Altair\Doctor\Result\AgentAction;
use Altair\Doctor\Result\CheckResult;
use Orion\Adapter\Capability;
use Orion\IndexName;
use Override;
use Throwable;

use function sprintf;

/**
 * `orion_alias_resolves`: every alias points at a registered version that is on its engine, and where the engine
 * keeps the alias itself, the engine points it at the same version as the registry. A disagreement is what a swap
 * interrupted between the engine and the database leaves; resuming the reindex settles it.
 */
final readonly class AliasResolvesCheck implements CheckInterface
{
    public const string NAME = 'orion_alias_resolves';

    public function __construct(private Probe $probe)
    {
    }

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    #[Override]
    public function dependsOn(): array
    {
        return ['database_reachable', EngineReachableCheck::NAME];
    }

    #[Override]
    public function run(): CheckResult
    {
        try {
            $graph = $this->probe->graph();
            $problems = [];
            $first = null;
            foreach ($graph->aliases()->aliases() as $alias) {
                $problem = $this->problem($alias);
                if ($problem !== null) {
                    $problems[] = $problem;
                    $first ??= $alias->value;
                }
            }
        } catch (Throwable $failure) {
            return CheckResult::error(self::NAME, Probe::message($failure));
        }
        if ($first !== null) {
            return CheckResult::error(self::NAME, Probe::summary($problems), 'Resume an interrupted reindex: bin/altair orion:reindex ' . $first, AgentAction::runCommand('bin/altair orion:reindex ' . $first));
        }

        return CheckResult::ok(self::NAME, 'Every alias resolves to its registered version.');
    }

    private function problem(IndexName $alias): ?string
    {
        $graph = $this->probe->graph();
        try {
            $current = $graph->aliases()->current($alias);
        } catch (Throwable $disagreement) {
            return sprintf('%s: %s', $alias->value, Probe::message($disagreement));
        }
        if ($current === null) {
            return null;
        }
        $engine = $graph->engines()->get($current->engine);
        if ($engine->inspect($current->physical) === null) {
            return sprintf('%s: its version %s is missing from the engine %s', $alias->value, $current->physical->value, $current->engine);
        }
        if (!$engine->supports(Capability::NativeAliases)) {
            return null;
        }
        $resolved = $engine->resolve($alias);
        if ($resolved === null || !$resolved->equals($current->physical)) {
            return sprintf('%s: the engine points it at %s, the registry at %s', $alias->value, $resolved->value ?? 'nothing', $current->physical->value);
        }

        return null;
    }
}
