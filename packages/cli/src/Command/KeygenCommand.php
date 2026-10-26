<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use Orion\Cli\Result;
use Orion\Receipt\SodiumSigner;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * `orion receipts:keygen --kid=<id>`: a new Ed25519 key pair for signing receipts, base64, for the host's secret store.
 * The output is a secret; it is random by design, the one command whose output never repeats.
 */
final class KeygenCommand extends OrionCommand
{
    protected function define(): void
    {
        $this->setName('receipts:keygen')
            ->setDescription('Generate an Ed25519 key pair for signing receipts')
            ->addOption('kid', null, InputOption::VALUE_REQUIRED, 'The key id receipts will name', 'orion-1');
    }

    protected function handle(InputInterface $input): Result
    {
        $pair = SodiumSigner::generateKeyPair();
        $kid = (string) $input->getOption('kid');
        // Loading the pair checks the kid and the key before anything is printed.
        SodiumSigner::fromBase64($pair['secret_key'], $kid);

        return new Result(['kid' => $kid, 'secret_key' => $pair['secret_key'], 'public_key' => $pair['public_key']]);
    }
}
