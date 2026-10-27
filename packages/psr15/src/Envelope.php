<?php

declare(strict_types=1);

namespace Orion\Psr15;

use Orion\Query\Contribution;
use Orion\Query\Hit;
use Orion\Query\Page;
use Orion\Query\ResultSet;
use stdClass;

use function array_map;

/**
 * The response body of a search: the {@see ResultSet} envelope as JSON, the same on every engine and host. Empty
 * maps are objects, so a client never sees `[]` where a map belongs.
 */
final class Envelope
{
    /**
     * @return array<string, mixed>
     */
    public static function of(ResultSet $results, Page $page): array
    {
        $body = [
            'hits' => array_map(self::hit(...), $results->hits),
            'total' => $results->total,
            'total_is_estimate' => $results->totalIsEstimate,
            'facets' => self::map(array_map(self::map(...), $results->facets)),
            'took_ms' => $results->tookMs,
            'page' => $page->page,
            'size' => $page->size,
        ];
        if ($results->fusion !== null) {
            $body['fusion'] = [
                ...$results->fusion->fusion->toArray(),
                'contributions' => array_map(
                    static fn(Contribution $c): array => ['id' => $c->id, 'source' => $c->source, 'rank' => $c->rank, 'score' => $c->score],
                    $results->fusion->contributions,
                ),
            ];
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private static function hit(Hit $hit): array
    {
        return ['id' => $hit->id, 'score' => $hit->score, 'source' => self::map($hit->source), 'highlights' => self::map($hit->highlights)];
    }

    /**
     * @param array<array-key, mixed> $map
     * @return array<array-key, mixed>|stdClass
     */
    private static function map(array $map): array|stdClass
    {
        return $map === [] ? new stdClass() : $map;
    }
}
