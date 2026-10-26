<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use InvalidArgumentException;
use Orion\Cli\Result;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

use function is_string;
use function preg_match;

/**
 * `orion verify [--head=<sequence>:<hash>]`: walks the receipt chain and checks every hash, link and signature, that
 * it reaches the head issued (and the anchored one), and that it attests every alias's current state; exits 1 on any
 * break. It prints the head: record it outside the database to anchor the next run.
 */
final class VerifyCommand extends OrionCommand
{
    protected function define(): void
    {
        $this->setName('verify')
            ->setDescription('Verify the receipt chain: hashes, links, signatures, length, and that it attests every alias')
            ->addOption('head', null, InputOption::VALUE_REQUIRED, 'A head recorded elsewhere, <sequence>:<hash>, that the chain must still reach');
    }

    protected function handle(InputInterface $input): Result
    {
        $head = $input->getOption('head');
        [$sequence, $hash] = [null, null];
        if (is_string($head) && $head !== '') {
            if (preg_match('/^([1-9][0-9]{0,17}):([0-9a-f]{64})\z/', $head, $match) !== 1) {
                throw new InvalidArgumentException('--head takes <sequence>:<sha256 hex>, as `orion verify` prints them.');
            }
            [$sequence, $hash] = [(int) $match[1], $match[2]];
        }
        $verification = $this->context->graph($input)->verifier()->verify($sequence, $hash);

        return new Result($verification->toArray(), $verification->ok() ? Command::SUCCESS : Command::FAILURE);
    }
}
