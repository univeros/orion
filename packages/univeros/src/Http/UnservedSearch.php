<?php

declare(strict_types=1);

namespace Univeros\Orion\Http;

use LogicException;

/**
 * The domain behind the routes the module registers for its endpoints: never reached, because {@see SearchEndpoints}
 * answers the request first, just inside the action. The route exists so the framework's dispatcher routes the
 * request (and its authentication runs) instead of answering 404 before the module's middleware.
 */
final class UnservedSearch
{
    public function __invoke(mixed $input): never
    {
        throw new LogicException('The search endpoints are served by the SearchEndpoints middleware, which did not run: the module\'s middleware must be in the pipeline.');
    }
}
