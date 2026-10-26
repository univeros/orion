<?php

declare(strict_types=1);

namespace Orion\Cli;

use InvalidArgumentException;
use Orion\IndexName;
use Orion\Orion;
use Orion\Schema\IndexSchema;
use Orion\Schema\MigrationPlan;
use Orion\Support\Assert;
use Orion\Wiring\Graph;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Yaml\Yaml;

use function count;
use function file_get_contents;
use function implode;
use function is_file;
use function is_string;
use function json_decode;
use function preg_match;
use function sprintf;
use function str_ends_with;

use const JSON_THROW_ON_ERROR;

/**
 * What the commands share: the application's graph (the one the host passed, or the bootstrap's), schemas read from
 * files, and the engine an index belongs to.
 */
final class Context
{
    private ?Orion $loaded = null;

    public function __construct(private readonly ?Orion $orion = null)
    {
    }

    public function graph(InputInterface $input): Graph
    {
        return ($this->orion ?? ($this->loaded ??= Bootstrap::load($input->getOption('bootstrap'))))->graph();
    }

    public static function index(InputInterface $input): IndexName
    {
        return IndexName::of(self::string($input->getArgument('index'), 'index'));
    }

    /**
     * A schema file in the declarative form (`IndexSchema::toArray()`), as JSON or YAML; when an index is named, the
     * file must declare that index. The `search:` manifest (WP8) replaces the file.
     */
    public static function schema(mixed $file, ?IndexName $index = null): IndexSchema
    {
        $file = self::string($file, 'schema');
        if (!is_file($file)) {
            throw new InvalidArgumentException(sprintf('The schema file %s does not exist.', $file));
        }
        $data = str_ends_with($file, '.json') ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : Yaml::parseFile($file);
        $schema = IndexSchema::fromArray($data);
        if ($index !== null && !$schema->index->equals($index)) {
            throw new InvalidArgumentException(sprintf('%s declares %s, not %s.', $file, Assert::show($schema->index->value), Assert::show($index->value)));
        }

        return $schema;
    }

    /**
     * `--engine`, else the engine that built the index, else the only engine configured.
     */
    public static function engine(InputInterface $input, Graph $graph, IndexName $index): string
    {
        $option = $input->hasOption('engine') ? $input->getOption('engine') : null;
        $built = $graph->aliases()->current($index)?->engine;
        if (is_string($option) && $option !== '') {
            $graph->engines()->get($option);
            if ($built !== null && $built !== $option) {
                throw new InvalidArgumentException(sprintf('%s was built by the engine %s, not %s.', Assert::show($index->value), Assert::show($built), Assert::show($option)));
            }

            return $option;
        }
        if ($built !== null) {
            return $built;
        }
        $names = $graph->engines()->names();

        return count($names) === 1 ? $names[0] : throw new InvalidArgumentException(sprintf('Pass --engine: %s is not built and several engines are configured (%s).', Assert::show($index->value), implode(', ', $names)));
    }

    /**
     * Where a declared schema stands against the index: missing (not built), in sync, additive or breaking drift.
     */
    public static function state(MigrationPlan $plan, bool $built): string
    {
        return match (true) {
            !$built => 'missing',
            $plan->isEmpty() => 'in_sync',
            $plan->requiresReindex() => 'breaking',
            default => 'additive',
        };
    }

    public static function count(mixed $value, string $option, int $minimum): int
    {
        $value = self::string($value, $option);
        if (preg_match('/^\d{1,9}\z/', $value) !== 1 || (int) $value < $minimum) {
            throw new InvalidArgumentException(sprintf('--%s takes a whole number of at least %d, not %s.', $option, $minimum, Assert::show($value)));
        }

        return (int) $value;
    }

    private static function string(mixed $value, string $name): string
    {
        return is_string($value) && $value !== '' ? $value : throw new InvalidArgumentException(sprintf('%s is required.', $name));
    }
}
