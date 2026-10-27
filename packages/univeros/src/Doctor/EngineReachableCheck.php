<?php

declare(strict_types=1);

namespace Univeros\Orion\Doctor;

use Altair\Doctor\Contracts\CheckInterface;
use Altair\Doctor\Result\CheckResult;
use Orion\IndexName;
use Override;
use Throwable;

use function implode;
use function sprintf;

/**
 * `orion_engine_reachable`: every configured engine answers. Nothing to fix from here: the connection is the setting.
 */
final readonly class EngineReachableCheck implements CheckInterface
{
    public const string NAME = 'orion_engine_reachable';
    private const string PROBE = 'orion_doctor_probe';

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
            $engines = $this->probe->graph()->engines();
            $failures = [];
            foreach ($engines->names() as $name) {
                try {
                    $engines->get($name)->inspect(IndexName::of(self::PROBE));
                } catch (Throwable $unreachable) {
                    $failures[] = sprintf('%s: %s', $name, Probe::message($unreachable));
                }
            }
        } catch (Throwable $failure) {
            return CheckResult::error(self::NAME, Probe::message($failure), 'Configure Orion: ORION_ENGINE, ORION_TENANCY and the engine\'s URL.');
        }
        if ($failures !== []) {
            return CheckResult::error(self::NAME, Probe::summary($failures), 'Check the engine\'s URL (ORION_ELASTICSEARCH_URL, ORION_OPENSEARCH_URL, ORION_SOLR_URL) and that the server is up.');
        }

        return CheckResult::ok(self::NAME, 'Reachable: ' . implode(', ', $engines->names()) . '.');
    }
}
