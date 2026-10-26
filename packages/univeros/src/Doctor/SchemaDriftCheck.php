<?php

declare(strict_types=1);

namespace Univeros\Orion\Doctor;

use Altair\Doctor\Contracts\FixableCheckInterface;
use Altair\Doctor\Result\AgentAction;
use Altair\Doctor\Result\CheckResult;
use Orion\Manifest\Declaration;
use Override;
use Throwable;

use function sprintf;

/**
 * `orion_schema_drift`: every built index matches its declaration. Breaking drift is an error whose agent action is
 * the reindex, never applied from here; additive drift (new fields, or a query-time change to the registration) is a
 * warning that `--fix` applies in place, with a receipt.
 */
final readonly class SchemaDriftCheck implements FixableCheckInterface
{
    public const string NAME = 'orion_schema_drift';

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
            [$breaking, $additive] = $this->drifted();
        } catch (Throwable $failure) {
            return CheckResult::error(self::NAME, Probe::message($failure));
        }
        if ($breaking !== []) {
            $index = $breaking[0]->schema->index->value;

            return CheckResult::error(self::NAME, self::describe($breaking, $additive), 'Rebuild it by replay: bin/altair orion:reindex ' . $index, AgentAction::runCommand('bin/altair orion:reindex ' . $index));
        }
        if ($additive !== []) {
            return CheckResult::warn(self::NAME, self::describe($breaking, $additive), 'bin/altair doctor --fix --only=' . self::NAME, AgentAction::runCommand('bin/altair doctor --fix --only=' . self::NAME));
        }

        return CheckResult::ok(self::NAME, 'Every built index matches its declaration.');
    }

    /**
     * Applies the additive drift; fixed only when a fresh look finds no drift left. A failure is logged with its cause.
     */
    #[Override]
    public function fix(): bool
    {
        try {
            [, $additive] = $this->drifted();
            foreach ($additive as $declaration) {
                try {
                    $this->probe->graph()->planner($this->probe->engineOf($declaration))->apply($declaration->schema);
                } catch (Throwable $failure) {
                    $this->probe->graph()->logger()->error('orion doctor --fix could not apply {index}: {reason}', ['index' => $declaration->schema->index->value, 'reason' => Probe::message($failure), 'exception' => $failure]);
                }
            }

            return $this->drifted() === [[], []];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array{list<Declaration>, list<Declaration>} breaking, additive
     */
    private function drifted(): array
    {
        $graph = $this->probe->graph();
        $breaking = [];
        $additive = [];
        foreach ($graph->manifest()->declarations() as $declaration) {
            $current = $graph->aliases()->current($declaration->schema->index);
            if ($current === null) {
                continue;
            }
            $plan = $graph->planner($current->engine)->plan($declaration->schema);
            if ($plan->requiresReindex()) {
                $breaking[] = $declaration;
            } elseif (!$plan->isEmpty() || $current->schema->hash() !== $declaration->schema->hash()) {
                $additive[] = $declaration;
            }
        }

        return [$breaking, $additive];
    }

    /**
     * @param list<Declaration> $breaking
     * @param list<Declaration> $additive
     */
    private static function describe(array $breaking, array $additive): string
    {
        $problems = [];
        foreach ($breaking as $declaration) {
            $problems[] = sprintf('%s: breaking drift, needs a reindex', $declaration->schema->index->value);
        }
        foreach ($additive as $declaration) {
            $problems[] = sprintf('%s: additive drift, applies in place', $declaration->schema->index->value);
        }

        return Probe::summary($problems);
    }
}
