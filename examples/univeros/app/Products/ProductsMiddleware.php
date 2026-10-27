<?php

declare(strict_types=1);

namespace App\Products;

use Cycle\Database\DatabaseProviderInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Orion\IndexName;
use Orion\Outbox\Dispatcher;
use Orion\Outbox\Outbox;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

use function is_array;
use function is_string;
use function json_decode;
use function preg_match;
use function strlen;

/**
 * The application's product writes. A write saves the product and records it in Orion's outbox in one transaction on
 * the application's own connection, then hands the index to the dispatcher once the transaction has committed. The
 * search is not here: `GET /products/search` is the endpoint `api/products/search.yaml` declares, served by the
 * Orion module through whichever version the alias points at, so it keeps working through a reindex.
 *
 *   POST   /products              {"id": "...", "title": "...", "sku": "..."}
 *   DELETE /products/{id}
 *
 * A demo: put the application's authentication in front of the writes before copying it.
 */
final readonly class ProductsMiddleware implements MiddlewareInterface
{
    private const string INDEX = 'products';
    private const string ID = '/^[A-Za-z0-9_-]{1,64}\z/';
    private const int MAX_TEXT = 255;

    public function __construct(
        private DatabaseProviderInterface $databases,
        private Outbox $outbox,
        private Dispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();

        return match (true) {
            $method === 'POST' && $path === '/products' => $this->save($request),
            $method === 'DELETE' && preg_match('#^/products/([A-Za-z0-9_-]{1,64})\z#', $path, $match) === 1 => $this->remove($match[1]),
            default => $handler->handle($request),
        };
    }

    private function save(ServerRequestInterface $request): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        $product = [];
        foreach (['id', 'title', 'sku'] as $field) {
            $value = is_array($body) ? ($body[$field] ?? null) : null;
            if (!is_string($value) || $value === '' || strlen($value) > self::MAX_TEXT || ($field === 'id' && preg_match(self::ID, $value) !== 1)) {
                return new JsonResponse(['error' => $field . ' is required: ' . ($field === 'id' ? 'letters, digits, _ and -, at most 64' : 'text, at most 255 bytes')], 422);
            }
            $product[$field] = $value;
        }
        $index = IndexName::of(self::INDEX);
        $entry = $this->databases->database()->transaction(function ($database) use ($product, $index) {
            $database->delete('products', ['id' => $product['id']])->run();
            $database->insert('products')->values($product)->run();

            return $this->outbox->index($index, $product['id'], ['title' => $product['title'], 'sku' => $product['sku']]);
        });
        $this->drainAfterCommit($index);

        return new JsonResponse(['id' => $product['id'], 'offset' => $entry->offset], 201);
    }

    private function remove(string $id): ResponseInterface
    {
        $index = IndexName::of(self::INDEX);
        $entry = $this->databases->database()->transaction(function ($database) use ($id, $index) {
            $database->delete('products', ['id' => $id])->run();

            return $this->outbox->delete($index, $id);
        });
        $this->drainAfterCommit($index);

        return new JsonResponse(['id' => $id, 'offset' => $entry->offset]);
    }

    /**
     * The write has committed: a failing drain (the engine is down) must not turn it into an error. The entry waits in
     * the outbox; the next drain, or `bin/altair orion:outbox:drain`, writes it.
     */
    private function drainAfterCommit(IndexName $index): void
    {
        try {
            $this->dispatcher->dispatch($index);
        } catch (Throwable $failure) {
            $this->logger->warning('The products drain failed; the outbox keeps the write: {reason}', ['reason' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
