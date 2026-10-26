<?php

declare(strict_types=1);

namespace Univeros\Orion\Doctor;

use Altair\Doctor\Contracts\FixableCheckInterface;
use Altair\Doctor\Result\AgentAction;
use Altair\Doctor\Result\CheckResult;
use Orion\IndexName;
use Orion\Lifecycle\IndexNamer;
use Orion\Manifest\Declaration;
use Orion\Registry\IndexRegistration;
use Override;
use Throwable;

use function count;
use function sprintf;

use const DATE_ATOM;

/**
 * `orion_registry_current`: every index a manifest declares is built. `--fix` builds what is missing, after clearing
 * what a crashed create left: a claim never aliased, or an unregistered first version on the engine. Either is
 * dropped only when it holds no documents, and a claim only when it is older than {@see CLAIM_GRACE_SECONDS} and on the
 * declared engine (a younger one may be a create still running); anything else is left for a person to look at.
 */
final readonly class RegistryCurrentCheck implements FixableCheckInterface
{
    public const string NAME = 'orion_registry_current';
    /** How old a claim must be before it is taken for a crashed create rather than one still running. */
    public const int CLAIM_GRACE_SECONDS = 300;

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
            $problems = [];
            foreach ($this->unbuilt() as $declaration) {
                $problems[] = $this->describe($declaration);
            }
        } catch (Throwable $failure) {
            return CheckResult::error(self::NAME, Probe::message($failure));
        }
        if ($problems === []) {
            return CheckResult::ok(self::NAME, 'Every declared index is built.');
        }

        return CheckResult::warn(self::NAME, Probe::summary($problems), 'bin/altair doctor --fix --only=' . self::NAME, AgentAction::runCommand('bin/altair doctor --fix --only=' . self::NAME));
    }

    /**
     * Fixed only when a fresh look finds nothing left to build; a failure is logged with its cause.
     */
    #[Override]
    public function fix(): bool
    {
        try {
            foreach ($this->unbuilt() as $declaration) {
                try {
                    $this->build($declaration);
                } catch (Throwable $failure) {
                    $this->probe->graph()->logger()->error('orion doctor --fix could not build {index}: {reason}', ['index' => $declaration->schema->index->value, 'reason' => Probe::message($failure), 'exception' => $failure]);
                }
            }

            return $this->unbuilt() === [];
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<Declaration>
     */
    private function unbuilt(): array
    {
        $graph = $this->probe->graph();
        $unbuilt = [];
        foreach ($graph->manifest()->declarations() as $declaration) {
            if ($graph->aliases()->current($declaration->schema->index) === null) {
                $unbuilt[] = $declaration;
            }
        }

        return $unbuilt;
    }

    private function describe(Declaration $declaration): string
    {
        $graph = $this->probe->graph();
        $alias = $declaration->schema->index;
        $claims = $graph->registry()->versions($alias);
        if ($claims !== []) {
            return $this->isRecent($claims[0])
                ? sprintf('%s: a create of %s is in progress (claimed at %s)', $alias->value, $claims[0]->physical->value, $claims[0]->createdAt->format(DATE_ATOM))
                : sprintf('%s: %s was claimed but never aliased (a create that crashed)', $alias->value, $claims[0]->physical->value);
        }
        $first = IndexNamer::physical($alias, 1);
        if ($graph->engines()->get($this->probe->engineOf($declaration))->inspect($first) !== null) {
            return sprintf('%s: %s is on the engine but not registered (a create that crashed)', $alias->value, $first->value);
        }

        return sprintf('%s is declared in %s but not built', $alias->value, $declaration->file);
    }

    private function isRecent(IndexRegistration $claim): bool
    {
        return $claim->createdAt->modify(sprintf('+%d seconds', self::CLAIM_GRACE_SECONDS)) > $this->probe->graph()->clock()->now();
    }

    /**
     * Drops what a crashed create left, and says so in the log: the drop is not a lifecycle operation, so it has no receipt.
     */
    private function drop(string $engine, IndexName $physical, string $what): void
    {
        $graph = $this->probe->graph();
        $graph->logger()->warning('orion doctor --fix dropped {physical} on {engine}, {what} left by a crashed create.', ['physical' => $physical->value, 'engine' => $engine, 'what' => $what]);
        $graph->engines()->get($engine)->drop($physical);
    }

    private function build(Declaration $declaration): bool
    {
        $graph = $this->probe->graph();
        $engine = $this->probe->engineOf($declaration);
        $adapter = $graph->engines()->get($engine);
        $alias = $declaration->schema->index;
        foreach ($graph->registry()->versions($alias) as $claim) {
            // A claim on another engine, or one young enough to be a create still running, is left for a person.
            if ($claim->engine !== $engine || $this->isRecent($claim)) {
                return false;
            }
            if ($adapter->inspect($claim->physical) !== null && !$this->probe->isEmpty($engine, $claim->physical)) {
                return false;
            }
            $this->drop($engine, $claim->physical, 'a claim never aliased');
            $graph->registry()->forget($claim->physical);
        }
        $first = IndexNamer::physical($alias, 1);
        if ($adapter->inspect($first) !== null) {
            if (!$this->probe->isEmpty($engine, $first)) {
                return false;
            }
            $this->drop($engine, $first, 'an unregistered first version');
        }
        $graph->planner($engine)->apply($declaration->schema);

        return $graph->aliases()->current($alias) !== null && count($graph->registry()->versions($alias)) > 0;
    }
}
