<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore;

use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Resource\ResourceInfo;

/**
 * Keeps cumulative metrics in a PHP array, with the rules of RedisStore. One process sees only
 * its own data. state() returns the array, and the constructor takes it back, so that another
 * store can persist it between processes.
 *
 * @phpstan-type Meta array{name: string, unit: ?string, description: ?string, kind: 'sum'|'histogram'|'gauge', monotonic: bool, bounds: list<float>, scope: array{name: string, version: ?string, schemaUrl: ?string}}
 * @phpstan-type Series array{a: array<array-key, mixed>, st: int, v?: int|float, t?: int, s?: float, b?: array<int, int>}
 * @phpstan-type State array<string, array{meta: Meta, series: array<string, Series>}>
 */
final class InMemoryStore implements MetricStore
{
    private const OVERFLOW_KEY = 'overflow';

    /**
     * @param  State  $state  as state() returned it
     */
    public function __construct(
        private readonly int $cardinalityLimit = 2000,
        private array $state = [],
    ) {}

    public function merge(iterable $batch): void
    {
        foreach ($batch as $metric) {
            $data = $metric->data;
            if ($data instanceof Histogram) {
                foreach ($data->dataPoints as $point) {
                    if ($point->count !== 0 && is_finite($point->sum)) {
                        $this->add($metric, 'histogram', false, array_map(floatval(...), $point->explicitBounds), $point);
                    }
                }
            } elseif ($data instanceof Sum || $data instanceof Gauge) {
                [$kind, $monotonic] = $data instanceof Sum ? ['sum', $data->monotonic] : ['gauge', false];
                foreach ($data->dataPoints as $point) {
                    if (is_finite($point->value)) {
                        $this->add($metric, $kind, $monotonic, [], $point);
                    }
                }
            }
        }
    }

    public function collect(ResourceInfo $resource): array
    {
        $now = Clock::getDefault()->now();
        $metrics = [];
        foreach ($this->state as $metric) {
            $meta = $metric['meta'];
            $series = $metric['series'];
            $data = match ($meta['kind']) {
                'histogram' => new Histogram(array_map(fn (array $s) => self::histogramPoint($s, $meta['bounds'], $now), array_values($series)), Temporality::CUMULATIVE),
                'gauge' => new Gauge(array_map(fn (array $s) => new NumberDataPoint($s['v'] ?? 0, Attributes::create($s['a']), $s['t'] ?? 0, $now), array_values($series))),
                'sum' => new Sum(array_map(fn (array $s) => new NumberDataPoint($s['v'] ?? 0, Attributes::create($s['a']), $s['st'], $now), array_values($series)), Temporality::CUMULATIVE, $meta['monotonic']),
            };

            $metrics[] = new Metric(
                new InstrumentationScope($meta['scope']['name'], $meta['scope']['version'], $meta['scope']['schemaUrl'], Attributes::create([])),
                $resource,
                $meta['name'],
                $meta['unit'],
                $meta['description'],
                $data,
            );
        }

        return $metrics;
    }

    public function clear(): void
    {
        $this->state = [];
    }

    /**
     * @return State
     */
    public function state(): array
    {
        return $this->state;
    }

    /**
     * @param  'sum'|'histogram'|'gauge'  $kind
     * @param  list<float>  $bounds
     */
    private function add(Metric $metric, string $kind, bool $monotonic, array $bounds, NumberDataPoint|HistogramDataPoint $point): void
    {
        $scope = $metric->instrumentationScope;
        $scopeArray = ['name' => $scope->getName(), 'version' => $scope->getVersion(), 'schemaUrl' => $scope->getSchemaUrl()];
        // The identity of RedisStore: the same name with other bounds or another kind is another metric.
        $id = hash('xxh128', serialize([$scopeArray, $metric->name, $metric->unit, $kind, $monotonic, $bounds]));

        $attributes = $point->attributes->toArray();
        ksort($attributes);
        $key = hash('xxh128', serialize($attributes));

        $series = $this->state[$id]['series'] ?? [];
        if (! isset($series[$key]) && count($series) >= $this->cardinalityLimit) {
            [$key, $attributes] = [self::OVERFLOW_KEY, ['otel.metric.overflow' => true]];
        }
        $this->state[$id]['meta'] ??= [
            'name' => $metric->name,
            'unit' => $metric->unit,
            'description' => $metric->description,
            'kind' => $kind,
            'monotonic' => $monotonic,
            'bounds' => $bounds,
            'scope' => $scopeArray,
        ];

        $current = $series[$key] ?? ['a' => $attributes, 'st' => $point->startTimestamp];
        if ($point instanceof HistogramDataPoint) {
            $current['s'] = ($current['s'] ?? 0.0) + $point->sum;
            $buckets = $current['b'] ?? [];
            foreach ($point->bucketCounts as $i => $count) {
                if ($count !== 0) {
                    $buckets[(int) $i] = ($buckets[(int) $i] ?? 0) + $count;
                }
            }
            $current['b'] = $buckets;
        } elseif ($kind === 'gauge') {
            if (! isset($current['t']) || $point->timestamp >= $current['t']) {
                $current['v'] = $point->value;
                $current['t'] = $point->timestamp;
            }
        } else {
            $current['v'] = ($current['v'] ?? 0) + $point->value;
        }
        $series[$key] = $current;
        $this->state[$id]['series'] = $series;
    }

    /**
     * @param  Series  $series
     * @param  list<float>  $bounds
     */
    private static function histogramPoint(array $series, array $bounds, int $now): HistogramDataPoint
    {
        $buckets = [];
        for ($i = 0, $n = count($bounds); $i <= $n; $i++) {
            $buckets[] = $series['b'][$i] ?? 0;
        }

        return new HistogramDataPoint(array_sum($buckets), $series['s'] ?? 0.0, NAN, NAN, $buckets, $bounds, Attributes::create($series['a']), $series['st'], $now);
    }
}
