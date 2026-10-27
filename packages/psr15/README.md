# orion-search/psr15

The `search:` endpoints of Orion as a PSR-15 middleware. Each `search` block in a manifest declares a route; the
middleware serves it through the scoped searcher and answers failures as RFC 9457 problems.

```sh
composer require orion-search/psr15
```

```php
use Orion\Psr15\SearchMiddleware;

$middleware = new SearchMiddleware(
    $graph->manifest(),
    $graph->searcher(),
    $responseFactory,     // PSR-17
    $streamFactory,       // PSR-17
    $authorizer,          // optional: checks the permission an endpoint declares
    $logger,              // optional
    prefix: '/api',       // where the host mounts the routes
);
```

A `GET` endpoint reads its query string, a `POST` endpoint a JSON body, with the same keys: `q`, `filter`, `sort`,
`page`, `size`, and `vector` on a hybrid endpoint. A request may only name what the endpoint declares, and only these
keys: any other parameter (a tracking tag, a cache buster) is a 400, so a typo never silently drops a filter.

| Failure | Status | Problem type |
|---|---|---|
| no tenant in the request | 401 | `no-tenant` |
| the permission the endpoint declares is missing | 403 | `forbidden` |
| input that does not fit the endpoint or the schema | 400 | `invalid-request` |
| a clause the engine cannot run, a page past the window | 400 | `unsupported-clause` |
| a declared path with another method | 405 | `method-not-allowed` |
| configuration that does not fit, an engine failure | 500 | `search-failed` (logged) |

`RequestTenant` is a request-scoped tenant resolver for hosts without Polaris: a middleware that reads the tenant
from the request (an attribute the host's authentication set) and the `TenantResolver` Orion is configured with.

Declaring endpoints and calling them: the [Frameworks guide](../../docs/guides/frameworks.md#the-search-endpoints-over-psr-15).
