# Changelog

## 0.1.1 - 2026-11-22

- Fixed: 0.1.0 required `nyholm/psr7` and `symfony/http-client ^5.4`, which the package does not use. Symfony 5.4 cannot be installed with Laravel 12 or 13.

## 0.1.0 - 2026-11-22

First release.

- `MetricStore` contract, with `RedisStore` (phpredis or predis) and `InMemoryStore`. They follow the same rules: cumulative sums and histograms, the newest gauge value, series start timestamps, and a cardinality limit with an overflow series.
- `StoreExporter`, an OpenTelemetry push exporter that merges the delta of each collection into a store.
- `PrometheusTextFormat`, which renders the store in the Prometheus text format with the names of the OpenTelemetry Prometheus compatibility specification.
