<?php

declare(strict_types=1);

namespace Univeros\Orion;

use Altair\Configuration\Support\Env;
use Altair\Container\Container;
use Altair\Doctor\CheckRegistry;
use Altair\Doctor\Doctor;
use Altair\Messaging\Discovery\HandlerEntry;
use Altair\Messaging\Discovery\HandlerRegistry;
use Altair\Module\Contracts\ManifestContributorInterface;
use Altair\Module\Contracts\MigrationDirectoriesProviderInterface;
use Altair\Module\Contracts\ModuleInterface;
use Altair\Module\Migration\MigrationSource;
use Orion\Manifest\Loader;
use Orion\Orion;
use Orion\Outbox\Dispatcher;
use Orion\Outbox\Outbox;
use Orion\Wiring\Graph;
use Override;
use Univeros\Orion\AgentSpec\ManifestSection;
use Univeros\Orion\Bootstrap\OrionConfig;
use Univeros\Orion\Doctor\AliasResolvesCheck;
use Univeros\Orion\Doctor\DeadLettersCheck;
use Univeros\Orion\Doctor\EngineReachableCheck;
use Univeros\Orion\Doctor\OutboxLagCheck;
use Univeros\Orion\Doctor\Probe;
use Univeros\Orion\Doctor\ReceiptsVerifyCheck;
use Univeros\Orion\Doctor\RegistryCurrentCheck;
use Univeros\Orion\Doctor\SchemaDriftCheck;
use Univeros\Orion\Messaging\DrainIndex;
use Univeros\Orion\Messaging\DrainIndexHandler;

use function dirname;

/**
 * Orion as a Univeros module: one line in `config/modules.php`.
 */
final class Module implements ModuleInterface, MigrationDirectoriesProviderInterface, ManifestContributorInterface
{
    /** The doctor checks, in the order they run. */
    private const array CHECKS = [
        EngineReachableCheck::class,
        RegistryCurrentCheck::class,
        SchemaDriftCheck::class,
        AliasResolvesCheck::class,
        OutboxLagCheck::class,
        DeadLettersCheck::class,
        ReceiptsVerifyCheck::class,
    ];

    #[Override]
    public function name(): string
    {
        return 'univeros/orion';
    }

    /**
     * Binds one {@see Orion} built from what the container binds and the environment ({@see OrionConfig}), and its
     * services by type. Nothing is built until something resolves it, so `bin/altair db:migrate` runs before Orion is
     * configured. With the framework's messaging configured, the drain runs on the bus worker.
     */
    #[Override]
    public function apply(Container $container): void
    {
        $container->singleton(Orion::class, static fn(Container $c): Orion => Orion::create(OrionConfig::fromContainer($c, self::env($c))));
        $container->singleton(Graph::class, static fn(Orion $orion): Graph => $orion->graph());
        $container->singleton(Outbox::class, static fn(Graph $graph): Outbox => $graph->outbox());
        $container->singleton(Dispatcher::class, static fn(Graph $graph): Dispatcher => $graph->dispatcher());
        $container->singleton(DrainIndexHandler::class);

        // With univeros/doctor configured, the Orion checks join `bin/altair doctor`; with univeros/messaging, the drain
        // handler joins the bus. Decorators run when the service is resolved, so the order configurations and modules
        // apply in does not matter.
        $container->extend(CheckRegistry::class, static function (object $registry) use ($container): object {
            if ($registry instanceof CheckRegistry) {
                $probe = new Probe($container, self::env($container));
                foreach (self::CHECKS as $check) {
                    $registry->add(new $check($probe));
                }
            }

            return $registry;
        });
        // The framework builds its Doctor around the registry object itself, so nothing resolves the registry through
        // the container on `bin/altair doctor`: resolving it here runs the decorator above on that object.
        $container->extend(Doctor::class, static function (object $doctor, Container $c): object {
            $c->get(CheckRegistry::class);

            return $doctor;
        });
        $container->extend(HandlerRegistry::class, static function (object $registry): object {
            if ($registry instanceof HandlerRegistry) {
                $registry->register(new HandlerEntry(DrainIndex::class, DrainIndexHandler::class));
            }

            return $registry;
        });
    }

    /**
     * The environment through the container, so a `.env` the framework loaded is seen.
     */
    public static function env(Container $container): Env
    {
        $env = $container->has(Env::class) ? $container->get(Env::class) : null;

        return $env instanceof Env ? $env : new Env();
    }

    #[Override]
    public function manifestDescription(): string
    {
        return 'Orion: deterministic index lifecycle, transactional outbox, zero-downtime reindex and signed receipts.';
    }

    /**
     * The declared indices, commands and checks, from the manifests alone (no database or engine needed).
     */
    #[Override]
    public function manifest(Container $container): string
    {
        return ManifestSection::render((new Loader(...OrionConfig::manifestDirectories(self::env($container))))->load());
    }

    /**
     * @return list<MigrationSource>
     */
    #[Override]
    public function migrationDirectories(): array
    {
        return [new MigrationSource(dirname(__DIR__) . '/migrations', __NAMESPACE__ . '\\Migrations')];
    }
}
