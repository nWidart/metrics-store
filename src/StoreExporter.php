<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore;

use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use Throwable;

/**
 * Receives the delta data of one request and merges it into the store.
 * A store failure never reaches the caller.
 */
final class StoreExporter implements AggregationTemporalitySelectorInterface, MetricExporterInterface
{
    use LogsMessagesTrait;

    public function __construct(private readonly RedisStore $store) {}

    public function temporality(MetricMetadataInterface $metric): string
    {
        return Temporality::DELTA;
    }

    public function export(iterable $batch): bool
    {
        try {
            $this->store->merge($batch);

            return true;
        } catch (Throwable $e) {
            self::logWarning('Could not merge metrics into the store', ['exception' => $e]);

            return false;
        }
    }

    public function shutdown(): bool
    {
        return true;
    }
}
