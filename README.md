# Metrics Store

Cross-process aggregation for OpenTelemetry metrics in shared-nothing PHP runtimes such as PHP-FPM.

> **Status:** early development. Not usable yet.

Under PHP-FPM, each request merges its OpenTelemetry metrics into a shared store. An exporter reads the cumulative values from the store and sends them with the standard OpenTelemetry exporters. Read the [design](../../docs/design.md).

## Requirements

- PHP 8.2 or later
- Redis, with `ext-redis` or `predis/predis`

## Prometheus

`Nwidart\MetricsStore\Prometheus\PrometheusTextFormat` renders what `RedisStore::collect()` returns in the Prometheus text format, with the names of the OpenTelemetry Prometheus compatibility specification:

```php
$text = (new PrometheusTextFormat)->render($store->collect(ResourceInfoFactory::emptyResource()), ['service' => 'shop']);
// Serve $text with the header Content-Type: PrometheusTextFormat::CONTENT_TYPE
```
