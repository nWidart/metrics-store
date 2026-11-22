<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore;

use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Resource\ResourceInfo;

/**
 * Keeps cumulative OpenTelemetry metrics that many processes add to.
 *
 * Every store follows the same rules: a sum adds the deltas, a histogram adds its bucket counts
 * and its sum, a gauge keeps the value with the newest timestamp, a new series keeps its start
 * timestamp, and a metric with more series than the cardinality limit sends the extra attribute
 * sets to one series with the attribute otel.metric.overflow = true.
 */
interface MetricStore
{
    /**
     * Adds the delta data of one collection to the stored cumulative data.
     *
     * @param  iterable<Metric>  $batch
     */
    public function merge(iterable $batch): void;

    /**
     * Reads every stored series as cumulative data.
     *
     * @return list<Metric>
     */
    public function collect(ResourceInfo $resource): array;

    /** Removes every stored series. */
    public function clear(): void;
}
