<?php

declare(strict_types=1);

namespace Univeros\Orion\Observability;

use Altair\Observability\Metrics\Meter;
use Orion\Observability\Metrics;
use Override;

/**
 * Orion's counters and gauges on the framework {@see Meter}.
 */
final readonly class MeterMetrics implements Metrics
{
    public function __construct(private Meter $meter)
    {
    }

    #[Override]
    public function counter(string $name, float $value = 1.0, array $attributes = []): void
    {
        $this->meter->counter($name, $value, $attributes);
    }

    #[Override]
    public function gauge(string $name, float $value, array $attributes = []): void
    {
        $this->meter->gauge($name, $value, $attributes);
    }
}
