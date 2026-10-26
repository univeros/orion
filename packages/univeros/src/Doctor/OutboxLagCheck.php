<?php

declare(strict_types=1);

namespace Univeros\Orion\Doctor;

use Altair\Doctor\Contracts\CheckInterface;
use Altair\Doctor\Result\AgentAction;
use Altair\Doctor\Result\CheckResult;
use Override;
use Throwable;

use function preg_match;
use function sprintf;

/**
 * `orion_outbox_lag`: no index trails its committed writes by more than `ORION_DOCTOR_MAX_LAG` entries (1000 by
 * default). Lag is reported, never hidden; the drain closes it.
 */
final readonly class OutboxLagCheck implements CheckInterface
{
    public const string NAME = 'orion_outbox_lag';
    public const string MAX_LAG = 'ORION_DOCTOR_MAX_LAG';
    private const int DEFAULT_MAX_LAG = 1000;

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
        $setting = $this->probe->env(self::MAX_LAG);
        if ($setting !== null && preg_match('/^\d{1,12}\z/', $setting) !== 1) {
            return CheckResult::error(self::NAME, sprintf('%s must be a whole number of entries, not "%s".', self::MAX_LAG, $setting));
        }
        $max = $setting === null ? self::DEFAULT_MAX_LAG : (int) $setting;
        try {
            $drain = $this->probe->graph()->drain();
            $lagging = [];
            foreach ($this->probe->indices() as $index) {
                $lag = $drain->status($index)->lag;
                if ($lag > $max) {
                    $lagging[] = sprintf('%s trails by %d entries', $index->value, $lag);
                }
            }
        } catch (Throwable $failure) {
            return CheckResult::error(self::NAME, Probe::message($failure));
        }
        if ($lagging !== []) {
            return CheckResult::warn(self::NAME, Probe::summary($lagging), 'Run the drain, or its worker: bin/altair orion:outbox:drain', AgentAction::runCommand('bin/altair orion:outbox:drain'));
        }

        return CheckResult::ok(self::NAME, sprintf('No index trails by more than %d entries.', $max));
    }
}
