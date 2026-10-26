<?php

declare(strict_types=1);

namespace Orion\Cli\Command;

use Orion\Adapter\Capability;
use Orion\Adapter\SearchAdapter;
use Orion\Cli\Result;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;

use function is_string;

/**
 * `orion capabilities [<engine>]`: what each engine reports it can do (v1 §16).
 */
final class CapabilitiesCommand extends OrionCommand
{
    /**
     * @return array<string, bool>
     */
    public static function of(SearchAdapter $engine): array
    {
        $capabilities = [];
        foreach (Capability::cases() as $capability) {
            $capabilities[$capability->name] = $engine->supports($capability);
        }

        return $capabilities;
    }

    protected function define(): void
    {
        $this->setName('capabilities')
            ->setDescription('Print the capability matrix of each engine, or of one')
            ->addArgument('engine', InputArgument::OPTIONAL, 'One engine by name');
    }

    protected function handle(InputInterface $input): Result
    {
        $engines = $this->context->graph($input)->engines();
        $name = $input->getArgument('engine');
        $data = [];
        foreach (is_string($name) ? [$name] : $engines->names() as $engine) {
            $data[$engine] = self::of($engines->get($engine));
        }

        return new Result($data);
    }
}
