<?php

declare(strict_types=1);

namespace Univeros\Orion\Doctor;

use Altair\Doctor\Contracts\CheckInterface;
use Altair\Doctor\Result\AgentAction;
use Altair\Doctor\Result\CheckResult;
use Override;
use Throwable;
use Univeros\Orion\Bootstrap\OrionConfig;

use function sprintf;

/**
 * `orion_receipts_verify`: the receipt chain verifies, reaches the last receipt issued and attests every alias. A
 * warning when receipts are off: lifecycle operations then leave no evidence.
 */
final readonly class ReceiptsVerifyCheck implements CheckInterface
{
    public const string NAME = 'orion_receipts_verify';

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
            $graph = $this->probe->graph();
            if ($graph->config()->receiptSigner === null && $graph->config()->receiptVerifier === null) {
                $issued = $graph->receipts()->head();
                if ($issued > 0) {
                    return CheckResult::warn(self::NAME, sprintf('%d receipts exist but cannot be verified: no signing or verification key is configured.', $issued), sprintf('Set %s (public keys) to verify them, or the signing key to keep issuing them.', OrionConfig::RECEIPT_PUBLIC_KEYS), AgentAction::runCommand('bin/altair orion:verify --json'));
                }

                return CheckResult::warn(self::NAME, 'Receipts are off: lifecycle operations leave no signed record.', sprintf('Generate a key (bin/altair orion:receipts:keygen) and set %s and %s.', OrionConfig::RECEIPT_KID, OrionConfig::RECEIPT_SECRET_KEY), AgentAction::runCommand('bin/altair orion:receipts:keygen'));
            }
            $verification = $graph->verifier()->verify();
        } catch (Throwable $failure) {
            return CheckResult::error(self::NAME, Probe::message($failure));
        }
        if (!$verification->ok()) {
            return CheckResult::error(self::NAME, Probe::summary($verification->breaks), 'Find out who changed the receipts or the registry; bin/altair orion:verify --json lists every break.', AgentAction::runCommand('bin/altair orion:verify --json'));
        }
        $head = $verification->head;

        return CheckResult::ok(self::NAME, $head === null ? 'No receipts yet.' : sprintf('%d receipts verify; head %d:%s.', $verification->receipts, $head->sequence, $head->hash));
    }
}
