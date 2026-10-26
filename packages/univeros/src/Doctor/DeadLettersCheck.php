<?php

declare(strict_types=1);

namespace Univeros\Orion\Doctor;

use Altair\Doctor\Contracts\CheckInterface;
use Altair\Doctor\Result\AgentAction;
use Altair\Doctor\Result\CheckResult;
use Override;
use Throwable;

use function sprintf;

/**
 * `orion_dead_letters`: no document the engine refused is waiting. Each is an index missing a document's latest state.
 */
final readonly class DeadLettersCheck implements CheckInterface
{
    public const string NAME = 'orion_dead_letters';

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
        return ['database_reachable'];
    }

    #[Override]
    public function run(): CheckResult
    {
        try {
            $letters = $this->probe->graph()->deadLetters();
            $waiting = [];
            $first = null;
            foreach ($this->probe->indices() as $index) {
                $count = $letters->count($index);
                if ($count > 0) {
                    $waiting[] = sprintf('%s: %d refused', $index->value, $count);
                    $first ??= $index->value;
                }
            }
        } catch (Throwable $failure) {
            return CheckResult::error(self::NAME, Probe::message($failure));
        }
        if ($first !== null) {
            return CheckResult::error(self::NAME, Probe::summary($waiting), 'See why (bin/altair orion:outbox:retry ' . $first . ' --dry-run), fix the data or the schema, then retry.', AgentAction::runCommand('bin/altair orion:outbox:retry ' . $first));
        }

        return CheckResult::ok(self::NAME, 'No dead letters.');
    }
}
