<?php

declare(strict_types=1);

namespace Orion\Psr15;

use Orion\Query\Page;
use Orion\Query\ResultSet;

/**
 * The response body of a search: the {@see ResultSet} envelope as JSON, the same on every engine and host, with
 * the page it answers and, for a hybrid query, the fusion trace. Empty maps are objects, so a client never sees
 * `[]` where a map belongs.
 */
final class Envelope
{
    /**
     * @return array<string, mixed>
     */
    public static function of(ResultSet $results, Page $page): array
    {
        return [
            ...$results->toArray(),
            'page' => $page->page,
            'size' => $page->size,
            ...($results->fusion === null ? [] : ['fusion' => $results->fusion->toArray()]),
        ];
    }
}
