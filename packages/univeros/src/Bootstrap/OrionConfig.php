<?php

declare(strict_types=1);

namespace Univeros\Orion\Bootstrap;

use Altair\Configuration\Support\Env;
use Altair\Container\Container;
use Composer\InstalledVersions;
use Altair\Messaging\Contracts\MessageBusInterface;
use Altair\Observability\Metrics\Meter;
use Altair\Persistence\Exception\InvalidConfigurationException;
use Cycle\Database\DatabaseProviderInterface;
use InvalidArgumentException;
use Orion\Adapter\InMemory\InMemoryEngine;
use Orion\Receipt\ReceiptSigner;
use Orion\Receipt\ReceiptVerifier;
use Orion\Receipt\SodiumSigner;
use Orion\Receipt\SodiumVerifier;
use Orion\Registry\EngineRegistry;
use Orion\Storage\Storage;
use Orion\Tenancy\Tenancy;
use Orion\Wiring\Config;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use Univeros\Orion\Database\CycleStorage;
use Univeros\Orion\Doctor\Probe;
use Univeros\Orion\Messaging\BusDispatcher;
use Univeros\Orion\Observability\MeterMetrics;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function is_dir;
use function realpath;
use function rtrim;
use function is_string;
use function sprintf;
use function str_contains;
use function trim;

/**
 * Orion's {@see Config} from what the application's container binds and its environment (docs/spec/revision.md §5).
 * A binding always wins over the environment. Storage is Orion's SQL on the host's Cycle connection, so the outbox
 * commits with the entities; tenancy has no default and must be chosen; receipt keys are the host's. Engines are the
 * application's to bind (an `EngineRegistry`), because each engine package is the host's choice; `ORION_ENGINE=memory`
 * gives the in-memory engine for development.
 */
final class OrionConfig
{
    public const string ENGINE = 'ORION_ENGINE';
    public const string TENANCY = 'ORION_TENANCY';
    public const string RECEIPT_KID = 'ORION_RECEIPT_KID';
    public const string RECEIPT_SECRET_KEY = 'ORION_RECEIPT_SECRET_KEY';
    public const string RECEIPT_PREVIOUS_KEYS = 'ORION_RECEIPT_PREVIOUS_KEYS';
    public const string RECEIPT_PUBLIC_KEYS = 'ORION_RECEIPT_PUBLIC_KEYS';
    public const string MANIFESTS = 'ORION_MANIFESTS';

    public static function fromContainer(Container $container, Env $env): Config
    {
        return new Config(
            storage: self::storage($container),
            engines: self::engines($container, $env),
            tenancy: self::tenancy($container, $env),
            clock: self::bound($container, ClockInterface::class),
            logger: self::bound($container, LoggerInterface::class),
            events: self::bound($container, EventDispatcherInterface::class),
            cache: self::bound($container, CacheInterface::class),
            manifestDirectories: self::manifestDirectories($env),
            dispatcher: $container->has(MessageBusInterface::class) ? new BusDispatcher(self::get($container, MessageBusInterface::class)) : null,
            metrics: $container->has(Meter::class) ? new MeterMetrics(self::get($container, Meter::class)) : null,
            receiptSigner: self::bound($container, ReceiptSigner::class) ?? self::signer($env),
            receiptVerifier: self::bound($container, ReceiptVerifier::class) ?? self::verifier($env),
        );
    }

    /**
     * Where the `search:` manifests are: `ORION_MANIFESTS` (comma-separated, each of which must exist), else the
     * project's `api` directory when it has one. Never relative to the working directory, which under php-fpm, the
     * built-in server or a worker is not the project root.
     *
     * @return list<string>
     */
    public static function manifestDirectories(Env $env): array
    {
        $named = self::list($env, self::MANIFESTS);
        if ($named !== []) {
            return $named;
        }
        $default = self::projectRoot() . '/' . Config::DEFAULT_MANIFEST_DIRECTORY;

        return is_dir($default) ? [$default] : [];
    }

    /**
     * The application's root: where Composer installed the root package.
     */
    private static function projectRoot(): string
    {
        $root = InstalledVersions::getRootPackage()['install_path'];

        return rtrim((string) (realpath($root) ?: $root), '/');
    }

    private static function storage(Container $container): Storage
    {
        $bound = self::bound($container, Storage::class);
        if ($bound !== null) {
            return $bound;
        }
        if (!$container->has(DatabaseProviderInterface::class)) {
            throw new InvalidConfigurationException('Orion records its outbox on the application\'s Cycle connection: apply CycleOrmConfiguration before the modules, or bind Orion\Storage\Storage.');
        }

        return CycleStorage::on(self::get($container, DatabaseProviderInterface::class)->database());
    }

    private static function engines(Container $container, Env $env): EngineRegistry
    {
        $bound = self::bound($container, EngineRegistry::class);
        if ($bound !== null) {
            return $bound;
        }
        $engine = self::string($env, self::ENGINE);

        return match ($engine) {
            'memory' => new EngineRegistry(memory: new InMemoryEngine()),
            default => throw new InvalidConfigurationException(sprintf(
                'Bind Orion\'s engines in the application: $container->singleton(EngineRegistry::class, fn() => new EngineRegistry(elasticsearch: ElasticsearchEngine::connect($url))); %s=memory gives the in-memory engine for development%s.',
                self::ENGINE,
                $engine === null ? '' : sprintf(' (%s is "%s")', self::ENGINE, $engine),
            )),
        };
    }

    private static function tenancy(Container $container, Env $env): Tenancy
    {
        $bound = self::bound($container, Tenancy::class);
        if ($bound !== null) {
            return $bound;
        }

        return match (self::string($env, self::TENANCY)) {
            'single' => Tenancy::single(),
            default => throw new InvalidConfigurationException(sprintf('Choose Orion\'s tenancy: set %s=single for one tenant, or bind an Orion\Tenancy\Tenancy (the Polaris bridge does, from WP6). There is no default, so no index is shared by accident.', self::TENANCY)),
        };
    }

    private static function signer(Env $env): ?ReceiptSigner
    {
        $secret = self::string($env, self::RECEIPT_SECRET_KEY);
        if ($secret === null) {
            // Half a key configuration is a typo, not a choice: receipts would be off without anyone deciding so.
            foreach ([self::RECEIPT_KID, self::RECEIPT_PREVIOUS_KEYS] as $partial) {
                if (self::string($env, $partial) !== null) {
                    throw new InvalidConfigurationException(sprintf('%s is set but %s is not: set both to sign receipts, or neither.', $partial, self::RECEIPT_SECRET_KEY));
                }
            }

            return null;
        }
        $kid = self::string($env, self::RECEIPT_KID) ?? throw new InvalidConfigurationException(sprintf('%s needs %s, the key\'s id.', self::RECEIPT_SECRET_KEY, self::RECEIPT_KID));
        try {
            return SodiumSigner::fromBase64($secret, $kid, self::keys($env, self::RECEIPT_PREVIOUS_KEYS));
        } catch (InvalidArgumentException $invalid) {
            throw new InvalidConfigurationException(sprintf('%s, %s or %s is not a usable Ed25519 key: %s', self::RECEIPT_SECRET_KEY, self::RECEIPT_KID, self::RECEIPT_PREVIOUS_KEYS, $invalid->getMessage()), 0, $invalid);
        }
    }

    private static function verifier(Env $env): ?ReceiptVerifier
    {
        $keys = self::keys($env, self::RECEIPT_PUBLIC_KEYS);
        if ($keys === []) {
            return null;
        }
        try {
            return SodiumVerifier::fromBase64($keys);
        } catch (InvalidArgumentException $invalid) {
            throw new InvalidConfigurationException(sprintf('%s is not a list of kid:base64-public-key: %s', self::RECEIPT_PUBLIC_KEYS, $invalid->getMessage()), 0, $invalid);
        }
    }

    /**
     * `kid:base64,kid:base64`.
     *
     * @return array<string, string>
     */
    private static function keys(Env $env, string $name): array
    {
        $keys = [];
        foreach (self::list($env, $name) as $pair) {
            if (!str_contains($pair, ':')) {
                throw new InvalidConfigurationException(sprintf('%s takes kid:base64-public-key pairs, comma-separated.', $name));
            }
            [$kid, $key] = explode(':', $pair, 2);
            if (isset($keys[$kid])) {
                throw new InvalidConfigurationException(sprintf('%s names the kid %s twice.', $name, $kid));
            }
            $keys[$kid] = $key;
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private static function list(Env $env, string $name): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', self::string($env, $name) ?? '')), static fn(string $item): bool => $item !== ''));
    }

    private static function string(Env $env, string $name): ?string
    {
        $value = $env->get($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T|null
     */
    private static function bound(Container $container, string $id): ?object
    {
        return $container->has($id) ? self::get($container, $id) : null;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private static function get(Container $container, string $id): object
    {
        try {
            $value = $container->get($id);
        } catch (Throwable $failure) {
            throw new InvalidConfigurationException(sprintf('Orion could not resolve %s from the container: %s', $id, Probe::message($failure)), 0, $failure);
        }

        return $value instanceof $id ? $value : throw new InvalidConfigurationException(sprintf('The container binds %s to something else.', $id));
    }
}
