<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore;

use OpenTelemetry\API\Behavior\LogsMessagesTrait;
use OpenTelemetry\SDK\Metrics\AggregationTemporalitySelectorInterface;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\InstrumentType;
use OpenTelemetry\SDK\Metrics\MetricExporterInterface;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use Throwable;

/**
 * Receives the delta data of one request and merges it into the store.
 * A store failure never reaches the caller.
 *
 * Observable instruments are not exported. Their callbacks report a current value, not what the
 * request added, so merging them would add the full value on every request. They belong in the
 * export process, which reads the store; the SDK then never runs their callbacks in a request.
 */
final class StoreExporter implements AggregationTemporalitySelectorInterface, MetricExporterInterface
{
    use LogsMessagesTrait;

    public function __construct(private readonly RedisStore $store) {}

    public function temporality(MetricMetadataInterface $metric): ?string
    {
        return match ($metric->instrumentType()) {
            InstrumentType::ASYNCHRONOUS_COUNTER,
            InstrumentType::ASYNCHRONOUS_UP_DOWN_COUNTER,
            InstrumentType::ASYNCHRONOUS_GAUGE => null,
            default => Temporality::DELTA,
        };
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
