<?php

declare(strict_types=1);

namespace Univeros\Orion\Doctor;

use Altair\Configuration\Support\Env;
use Altair\Container\Container;
use LogicException;
use Orion\IndexName;
use Orion\Manifest\Declaration;
use Orion\Query\Query;
use Orion\Wiring\Graph;
use Throwable;

use function array_keys;
use function count;
use function implode;
use function is_string;
use function ksort;
use function preg_replace;
use function sprintf;
use function trim;

/**
 * What the Orion doctor checks share: the application's graph, resolved when a check runs (so a misconfiguration is a
 * failed check, not a failed boot), the indices declared in manifests and those built, and the engine each belongs to.
 */
final readonly class Probe
{
    public function __construct(private Container $container, private Env $env)
    {
    }

    public function graph(): Graph
    {
        $graph = $this->container->get(Graph::class);

        return $graph instanceof Graph ? $graph : throw new LogicException('The container binds Orion\Wiring\Graph to something else.');
    }

    public function env(string $name): ?string
    {
        $value = $this->env->get($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Every index declared or built, by name.
     *
     * @return list<IndexName>
     */
    public function indices(): array
    {
        $graph = $this->graph();
        $names = [];
        foreach ($graph->manifest()->declarations() as $declaration) {
            $names[$declaration->schema->index->value] = true;
        }
        foreach ($graph->aliases()->aliases() as $alias) {
            $names[$alias->value] = true;
        }
        ksort($names);
        $indices = [];
        foreach (array_keys($names) as $name) {
            $indices[] = IndexName::of((string) $name);
        }

        return $indices;
    }

    /**
     * The engine a declared index is built on: the one that built it, else the one its manifest names, else the only
     * engine configured.
     */
    public function engineOf(Declaration $declaration): string
    {
        $graph = $this->graph();
        $current = $graph->aliases()->current($declaration->schema->index);
        $engine = $current !== null ? $current->engine : $declaration->engine;
        if ($engine !== null) {
            return $engine;
        }
        $names = $graph->engines()->names();

        return count($names) === 1 ? $names[0] : throw new LogicException(sprintf('%s names no engine and several are configured (%s); add engine: to its manifest.', $declaration->file, implode(', ', $names)));
    }

    /**
     * Whether a physical index holds no document, after a refresh so nothing written is missed.
     */
    public function isEmpty(string $engine, IndexName $physical): bool
    {
        $adapter = $this->graph()->engines()->get($engine);
        $adapter->flush($physical, await: true);

        return $adapter->search($physical, Query::on($physical->value)->paginate(1, 1))->total === 0;
    }

    /**
     * A failure as the doctor prints it: credentials in a URL (`https://user:password@host`) are redacted, because the
     * report goes to CI logs and agents.
     */
    public static function message(Throwable $failure): string
    {
        return (string) preg_replace('#//[^/@\s]+@#', '//***@', $failure->getMessage());
    }

    /**
     * @param list<string> $problems
     */
    public static function summary(array $problems): string
    {
        return implode('; ', $problems);
    }
}
