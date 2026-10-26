<?php

declare(strict_types=1);

namespace App;

use Altair\Configuration\Support\Env;
use Altair\Container\Container;
use Altair\Http\Support\MiddlewarePriority;
use Altair\Module\Contracts\MiddlewareProviderInterface;
use Altair\Module\Contracts\ModuleInterface;
use App\Products\ProductsMiddleware;
use Orion\Elasticsearch\ElasticsearchEngine;
use Orion\Registry\EngineRegistry;
use Override;

/**
 * What this application adds around Orion: the search engine it runs (one binding), and its product routes.
 */
final class AppModule implements ModuleInterface, MiddlewareProviderInterface
{
    #[Override]
    public function name(): string
    {
        return 'app';
    }

    #[Override]
    public function apply(Container $container): void
    {
        $container->singleton(
            EngineRegistry::class,
            static fn(Env $env): EngineRegistry => new EngineRegistry(
                elasticsearch: ElasticsearchEngine::connect((string) $env->get('ORION_ELASTICSEARCH_URL', 'http://127.0.0.1:9200')),
            ),
        );
        $container->singleton(ProductsMiddleware::class);
    }

    /**
     * @return list<array{middleware: class-string<ProductsMiddleware>, priority: int}>
     */
    #[Override]
    public function middleware(): array
    {
        return [['middleware' => ProductsMiddleware::class, 'priority' => MiddlewarePriority::DISPATCHER - 10]];
    }
}
