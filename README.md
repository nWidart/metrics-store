# Metrics Store

Cross-process aggregation for OpenTelemetry metrics in shared-nothing PHP runtimes such as PHP-FPM.

> **Status:** early development. Not usable yet.

Under PHP-FPM, each request merges its OpenTelemetry metrics into a shared store. An exporter reads the cumulative values from the store and sends them with the standard OpenTelemetry exporters. Read the [design](../../docs/design.md).

## Requirements

- PHP 8.2 or later
- Redis, with `ext-redis` or `predis/predis`

## Stores

Every store implements `MetricStore` (`merge`, `collect`, `clear`) with the same rules: sums add their deltas, histograms add their buckets and sum, gauges keep the value with the newest timestamp, each series keeps its start timestamp, and extra attribute sets beyond the cardinality limit go to one overflow series.

- `RedisStore`: shared by every process that uses the same Redis. For production.
- `InMemoryStore`: one process only. `state()` returns its data as an array, and the constructor takes it back, so another store can persist it (laravel-metrics keeps it in a Laravel cache entry).

## Prometheus

`Nwidart\MetricsStore\Prometheus\PrometheusTextFormat` renders what `RedisStore::collect()` returns in the Prometheus text format, with the names of the OpenTelemetry Prometheus compatibility specification:

```php
$text = (new PrometheusTextFormat)->render($store->collect(ResourceInfoFactory::emptyResource()), ['service' => 'shop']);
// Serve $text with the header Content-Type: PrometheusTextFormat::CONTENT_TYPE
```
