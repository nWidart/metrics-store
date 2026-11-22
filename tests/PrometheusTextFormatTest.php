<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore\Tests;

use Nwidart\MetricsStore\Prometheus\PrometheusTextFormat;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Metrics\Data\DataInterface;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PrometheusTextFormatTest extends TestCase
{
    #[Test]
    public function a_histogram_has_cumulative_buckets_a_sum_and_a_count(): void
    {
        $output = $this->render(
            $this->metric('http.server.request.duration', 's', 'Duration of HTTP server requests.', new Histogram([
                new HistogramDataPoint(4, 0.75, NAN, NAN, [1, 2, 0, 1], [0.1, 0.25, 1], Attributes::create(['http.request.method' => 'GET', 'http.response.status_code' => 200]), 0, 0),
            ], Temporality::CUMULATIVE)),
        );

        $labels = 'http_request_method="GET",http_response_status_code="200",otel_scope_name="test"';
        $this->assertSame(<<<TEXT
            # HELP http_server_request_duration_seconds Duration of HTTP server requests.
            # TYPE http_server_request_duration_seconds histogram
            http_server_request_duration_seconds_bucket{{$labels},le="0.1"} 1
            http_server_request_duration_seconds_bucket{{$labels},le="0.25"} 3
            http_server_request_duration_seconds_bucket{{$labels},le="1"} 3
            http_server_request_duration_seconds_bucket{{$labels},le="+Inf"} 4
            http_server_request_duration_seconds_sum{{$labels}} 0.75
            http_server_request_duration_seconds_count{{$labels}} 4

            TEXT, $output);
    }

    #[Test]
    public function a_monotonic_sum_is_a_counter_and_an_annotation_is_not_a_unit(): void
    {
        $output = $this->render(
            $this->metric('laravel.cache.operations', '{operation}', null, new Sum([
                new NumberDataPoint(3, Attributes::create(['laravel.cache.result' => 'hit']), 0, 0),
            ], Temporality::CUMULATIVE, true)),
            $this->metric('orders.total', '', null, new Sum([new NumberDataPoint(2, Attributes::create([]), 0, 0)], Temporality::CUMULATIVE, true)),
        );

        $this->assertSame(<<<'TEXT'
            # TYPE laravel_cache_operations_total counter
            laravel_cache_operations_total{laravel_cache_result="hit",otel_scope_name="test"} 3
            # TYPE orders_total counter
            orders_total{otel_scope_name="test"} 2

            TEXT, $output);
    }

    #[Test]
    public function gauges_and_non_monotonic_sums_are_gauges_with_their_unit(): void
    {
        $output = $this->render(
            $this->metric('process.memory', 'By', null, new Gauge([new NumberDataPoint(1.5, Attributes::create([]), 0, 0)])),
            $this->metric('queue.depth', '{job}', null, new Sum([new NumberDataPoint(-2, Attributes::create([]), 0, 0)], Temporality::CUMULATIVE, false)),
            $this->metric('cache.hit_ratio', '1', null, new Gauge([new NumberDataPoint(0.25, Attributes::create([]), 0, 0)])),
            $this->metric('network.io', 'By/s', null, new Gauge([new NumberDataPoint(10, Attributes::create([]), 0, 0)])),
        );

        $this->assertStringContainsString("# TYPE process_memory_bytes gauge\nprocess_memory_bytes{otel_scope_name=\"test\"} 1.5\n", $output);
        $this->assertStringContainsString("# TYPE queue_depth gauge\nqueue_depth{otel_scope_name=\"test\"} -2\n", $output);
        $this->assertStringContainsString("cache_hit_ratio{otel_scope_name=\"test\"} 0.25\n", $output);
        $this->assertStringContainsString("network_io_bytes_per_second{otel_scope_name=\"test\"} 10\n", $output);
    }

    #[Test]
    public function names_and_values_are_sanitized_and_escaped(): void
    {
        $output = $this->render(
            $this->metric('9lives.café-orders', '', "Line one\nwith \\ slash", new Gauge([
                new NumberDataPoint(1, Attributes::create(['a.b' => 'x', 'a-b' => 'y', 'quote' => 'say "hi"'."\n", 'flag' => true, 'list' => [1, 2]]), 0, 0),
            ])),
        );

        $this->assertSame(<<<'TEXT'
            # HELP _9lives_caf_orders Line one\nwith \\ slash
            # TYPE _9lives_caf_orders gauge
            _9lives_caf_orders{a_b="y;x",flag="true",list="[1,2]",otel_scope_name="test",quote="say \"hi\"\n"} 1

            TEXT, $output);
    }

    #[Test]
    public function constant_labels_are_added_and_an_attribute_of_the_same_name_wins(): void
    {
        $output = (new PrometheusTextFormat)->render(
            [$this->metric('jobs', '', null, new Gauge([
                new NumberDataPoint(1, Attributes::create(['env' => 'staging']), 0, 0),
                new NumberDataPoint(2, Attributes::create([]), 0, 0),
            ]))],
            ['env' => 'production', 'app.name' => 'shop'],
        );

        $this->assertStringContainsString('jobs{app_name="shop",env="staging",otel_scope_name="test"} 1', $output);
        $this->assertStringContainsString('jobs{app_name="shop",env="production",otel_scope_name="test"} 2', $output);
    }

    #[Test]
    public function delta_metrics_conflicting_types_and_repeated_samples_are_left_out(): void
    {
        $output = $this->render(
            $this->metric('orders', '', null, new Sum([new NumberDataPoint(1, Attributes::create([]), 0, 0)], Temporality::DELTA, true)),
            $this->metric('orders.placed', '', null, new Gauge([new NumberDataPoint(1, Attributes::create([]), 0, 0)])),
            $this->metric('orders_placed', '', null, new Gauge([new NumberDataPoint(2, Attributes::create([]), 0, 0)])),
            $this->metric('orders_placed', '', null, new Sum([new NumberDataPoint(3, Attributes::create([]), 0, 0)], Temporality::CUMULATIVE, false)),
            $this->metric('orders_placed', '', null, new Histogram([], Temporality::CUMULATIVE)),
        );

        $this->assertSame(<<<'TEXT'
            # TYPE orders_placed gauge
            orders_placed{otel_scope_name="test"} 1

            TEXT, $output);
    }

    #[Test]
    public function two_scopes_with_one_name_stay_apart_and_a_version_is_a_label(): void
    {
        $output = $this->render(
            $this->metric('jobs', '', null, new Gauge([new NumberDataPoint(1, Attributes::create([]), 0, 0)])),
            $this->metric('jobs', '', null, new Gauge([new NumberDataPoint(2, Attributes::create([]), 0, 0)]), new InstrumentationScope('other', '1.2.0', null, Attributes::create([]))),
        );

        $this->assertSame(<<<'TEXT'
            # TYPE jobs gauge
            jobs{otel_scope_name="test"} 1
            jobs{otel_scope_name="other",otel_scope_version="1.2.0"} 2

            TEXT, $output);
    }

    #[Test]
    public function nothing_renders_as_an_empty_string(): void
    {
        $this->assertSame('', (new PrometheusTextFormat)->render([]));
    }

    private function render(Metric ...$metrics): string
    {
        return (new PrometheusTextFormat)->render($metrics);
    }

    private function metric(string $name, string $unit, ?string $description, DataInterface $data, ?InstrumentationScope $scope = null): Metric
    {
        return new Metric(
            $scope ?? new InstrumentationScope('test', null, null, Attributes::create([])),
            ResourceInfoFactory::emptyResource(),
            $name,
            $unit,
            $description,
            $data,
        );
    }
}
