<?php

declare(strict_types=1);

namespace App;

use Altair\Container\Container;
use Altair\Http\Support\MiddlewarePriority;
use Altair\Module\Contracts\MiddlewareProviderInterface;
use Altair\Module\Contracts\ModuleInterface;
use App\Products\ProductsMiddleware;
use Override;

/**
 * What this application adds around Orion: its product routes. The engine comes from `ORION_ENGINE` (see `.env`).
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
