<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use Orion\Cli\Result;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * `orion verify`: walks the receipt chain and checks every hash, link and signature; exits 1 on any break.
 */
final class VerifyCommand extends OrionCommand
{
    protected function define(): void
    {
        $this->setName('verify')->setDescription('Verify the receipt chain: hashes, links, signatures and length');
    }

    protected function handle(InputInterface $input): Result
    {
        $verification = $this->context->graph($input)->verifier()->verify();

        return new Result($verification->toArray(), $verification->ok() ? Command::SUCCESS : Command::FAILURE);
    }
}
