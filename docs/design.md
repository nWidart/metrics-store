# Design

## Problem

OpenTelemetry PHP aggregates metrics in memory. Under PHP-FPM, every request starts with a new `MeterProvider`, so cumulative counters reset on each request. The OpenTelemetry PHP maintainers advise delta temporality for PHP-FPM ([open-telemetry/opentelemetry-php#970](https://github.com/open-telemetry/opentelemetry-php/issues/970)). Prometheus cannot ingest delta, and the Collector's `deltatocumulative` processor drops deltas that arrive out of order ([keepsuit/laravel-opentelemetry#105](https://github.com/keepsuit/laravel-opentelemetry/issues/105)).

## Approach

Keep OpenTelemetry at both ends. Add only the missing part: aggregation across processes.

```
FPM request (any worker, any host)
  app code / instrumentation ──► OTel Meter API ──► OTel SDK (delta, this request only)
                                                     │ terminate(), after the response is sent
                                                     ▼
                                          StoreExporter: one pipelined Lua call
                                                     ▼
                                          Redis: cumulative series
                                                     ▲
Export side                                          │ read only
  scheduler `metrics:push` ──────────────────────────┤──► OTel OTLP exporter (cumulative)
  GET /metrics ──────────────────────────────────────┘──► Prometheus text format
```

## Decisions

1. **Redis is the v1 store.** Most Laravel applications already run Redis. One store serves PHP-FPM, queue workers, the scheduler, Octane and Lambda, and it aggregates across hosts. For development without Redis, a Laravel cache store can hold the data in one entry, written under a lock. APCu follows in a later version for applications without Redis. The CLI has its own APCu memory, so APCu needs export from an FPM request.
2. **Store cumulative values. Export cumulative values.** A cumulative read is idempotent: a lost or duplicate push does no harm. Prometheus reads cumulative data natively.
3. **Record through the OpenTelemetry API.** Libraries that use the OpenTelemetry API then feed the store with no extra work. The SDK cost per request was the main risk. A spike measured it before the packages were built: with an optimized autoloader (`composer dump-autoload -o`), booting the SDK takes about 75 µs per request.
4. **Instrument Laravel through its events.** `composer require` alone must give useful metrics, so the package must not require `ext-opentelemetry`.
5. **Store rules:**
   - Histograms use explicit bucket boundaries that are fixed per instrument, so that values from different requests can merge. Exponential histograms are out of scope for v1.
   - Each series stores its start timestamp. An empty store gives a new start timestamp, which backends read as a counter reset.
   - Each instrument has a cardinality limit (2000 by default, as in the OpenTelemetry specification). Extra attribute sets go to one overflow series.
   - Synchronous gauges have no clear meaning across workers. Observable gauges run their callbacks in the export process.
   - A store failure never fails the request.

## Packages

| Package | Contents |
|---|---|
| `nwidart/metrics-store` | Store contract (`MetricStore`), Redis store, in-memory store, `StoreExporter` (OpenTelemetry delta in, merge into the store), cumulative reader for the OpenTelemetry exporters, Prometheus text format. No framework dependency. |
| `nwidart/laravel-metrics` | Service provider, configuration, facade, instrumentation for HTTP, database, queue and cache, artisan commands, `/metrics` route, test fake. |
