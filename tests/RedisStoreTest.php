<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore\Tests;

use Exception;
use Nwidart\MetricsStore\Redis\PredisClient;
use Nwidart\MetricsStore\Redis\RedisClient;
use Nwidart\MetricsStore\RedisStore;
use Nwidart\MetricsStore\StoreExporter;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\Contrib\Otlp\MetricConverter;
use Opentelemetry\Proto\Metrics\V1\AggregationTemporality;
use Opentelemetry\Proto\Metrics\V1\Metric as ProtoMetric;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Connection\ConnectionException;

/**
 * Runs against a real Redis, database 15, prefix {test}:.
 * Start it with `docker compose up -d redis`. Set REDIS_HOST and REDIS_PORT to use another server.
 */
class RedisStoreTest extends TestCase
{
    protected const PREFIX = '{test}:';

    private RedisClient $client;

    private RedisStore $store;

    protected function setUp(): void
    {
        $this->client = $this->client();
        $this->store = new RedisStore($this->client, self::PREFIX);
        $this->store->clear();
    }

    protected function tearDown(): void
    {
        // Not set when setUp() skipped the test.
        if (isset($this->store)) {
            $this->store->clear();
        }
    }

    protected function client(): RedisClient
    {
        $client = new Client(['host' => self::host(), 'port' => self::port(), 'database' => 15, 'timeout' => 0.5]);
        try {
            $client->ping();
        } catch (ConnectionException $e) {
            self::markTestSkipped(sprintf('Redis is not reachable at %s:%d (%s). Run `docker compose up -d redis`.', self::host(), self::port(), $e->getMessage()));
        }

        return new PredisClient($client);
    }

    /** Client name the concurrency worker uses. */
    protected function clientName(): string
    {
        return 'predis';
    }

    protected static function host(): string
    {
        return getenv('REDIS_HOST') ?: '127.0.0.1';
    }

    protected static function port(): int
    {
        return (int) (getenv('REDIS_PORT') ?: 6379);
    }

    public function test_counter_is_cumulative_across_requests(): void
    {
        $before = Clock::getDefault()->now();
        $this->request(fn (MeterInterface $meter) => $meter->createCounter('requests')->add(1));
        $afterFirst = Clock::getDefault()->now();
        $this->request(fn (MeterInterface $meter) => $meter->createCounter('requests')->add(1));

        $sum = $this->data('requests', Sum::class);
        $point = $this->onlyPoint($sum->dataPoints);

        self::assertInstanceOf(NumberDataPoint::class, $point);
        self::assertSame(2, $point->value);
        self::assertTrue($sum->monotonic);
        self::assertSame(Temporality::CUMULATIVE, $sum->temporality);
        self::assertGreaterThanOrEqual($before, $point->startTimestamp);
        self::assertLessThanOrEqual($afterFirst, $point->startTimestamp);
    }

    public function test_histogram_merges_buckets_count_and_sum(): void
    {
        $bounds = ['ExplicitBucketBoundaries' => [10, 100]];
        $this->request(function (MeterInterface $meter) use ($bounds) {
            $histogram = $meter->createHistogram('latency', 'ms', null, $bounds);
            $histogram->record(5);
            $histogram->record(50);
        });
        $this->request(function (MeterInterface $meter) use ($bounds) {
            $histogram = $meter->createHistogram('latency', 'ms', null, $bounds);
            $histogram->record(500.5);
            $histogram->record(1);
        });

        $point = $this->onlyPoint($this->data('latency', Histogram::class)->dataPoints);

        self::assertInstanceOf(HistogramDataPoint::class, $point);
        self::assertSame(4, $point->count);
        self::assertSame(556.5, $point->sum);
        self::assertNan($point->min);
        self::assertNan($point->max);
        self::assertSame([2, 1, 1], $point->bucketCounts);
        self::assertSame([10.0, 100.0], $point->explicitBounds);
    }

    public function test_histogram_with_many_buckets_merges_sparse_bucket_increments(): void
    {
        $bounds = ['ExplicitBucketBoundaries' => [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14]];
        $this->request(function (MeterInterface $meter) use ($bounds) {
            $histogram = $meter->createHistogram('size', 'By', null, $bounds);
            $histogram->record(0.5);
            $histogram->record(0.5);
            $histogram->record(7.5);
        });
        $this->request(function (MeterInterface $meter) use ($bounds) {
            $histogram = $meter->createHistogram('size', 'By', null, $bounds);
            $histogram->record(100);
            $histogram->record(7.5);
        });

        $point = $this->onlyPoint($this->data('size', Histogram::class)->dataPoints);

        self::assertInstanceOf(HistogramDataPoint::class, $point);
        self::assertSame([2, 0, 0, 0, 0, 0, 0, 2, 0, 0, 0, 0, 0, 0, 1], $point->bucketCounts);
        self::assertSame(5, $point->count);
    }

    public function test_histogram_exports_without_min_and_max(): void
    {
        $this->request(fn (MeterInterface $meter) => $meter->createHistogram('latency')->record(3.5));

        $request = (new MetricConverter)->convert($this->collect());
        $metric = $request->getResourceMetrics()[0]?->getScopeMetrics()[0]?->getMetrics()[0];

        self::assertInstanceOf(ProtoMetric::class, $metric);
        $point = $metric->getHistogram()?->getDataPoints()[0];
        self::assertNotNull($point);
        self::assertFalse($point->hasMin());
        self::assertFalse($point->hasMax());
        self::assertSame(1, (int) $point->getCount());
    }

    public function test_integer_counter_stays_exact_beyond_double_precision(): void
    {
        $big = 2 ** 53;
        $this->request(fn (MeterInterface $meter) => $meter->createCounter('bytes')->add($big));
        $this->request(fn (MeterInterface $meter) => $meter->createCounter('bytes')->add(1));
        $this->request(fn (MeterInterface $meter) => $meter->createCounter('bytes')->add(1));

        $point = $this->onlyPoint($this->data('bytes', Sum::class)->dataPoints);

        self::assertInstanceOf(NumberDataPoint::class, $point);
        self::assertSame($big + 2, $point->value);
    }

    public function test_counter_with_integer_and_float_values_adds_both(): void
    {
        $this->request(fn (MeterInterface $meter) => $meter->createCounter('work')->add(2));
        $this->request(fn (MeterInterface $meter) => $meter->createCounter('work')->add(0.5));
        $this->request(fn (MeterInterface $meter) => $meter->createCounter('work')->add(3));

        $point = $this->onlyPoint($this->data('work', Sum::class)->dataPoints);

        self::assertInstanceOf(NumberDataPoint::class, $point);
        self::assertSame(5.5, $point->value);
    }

    public function test_up_down_counter_goes_negative(): void
    {
        $this->request(fn (MeterInterface $meter) => $meter->createUpDownCounter('queue')->add(2));
        $this->request(fn (MeterInterface $meter) => $meter->createUpDownCounter('queue')->add(-5));

        $sum = $this->data('queue', Sum::class);
        $point = $this->onlyPoint($sum->dataPoints);

        self::assertInstanceOf(NumberDataPoint::class, $point);
        self::assertSame(-3, $point->value);
        self::assertFalse($sum->monotonic);
    }

    public function test_gauge_keeps_the_latest_value(): void
    {
        $this->request(fn (MeterInterface $meter) => $meter->createGauge('temperature')->record(20.5));
        $this->request(fn (MeterInterface $meter) => $meter->createGauge('temperature')->record(18.25));

        $point = $this->onlyPoint($this->data('temperature', Gauge::class)->dataPoints);

        self::assertInstanceOf(NumberDataPoint::class, $point);
        self::assertSame(18.25, $point->value);
    }

    public function test_gauge_ignores_an_older_timestamp_beyond_double_precision(): void
    {
        // These timestamps are equal as doubles; only a string comparison tells them apart.
        $this->store->merge([$this->gauge(1.0, 1_700_000_000_000_000_001)]);
        $this->store->merge([$this->gauge(2.0, 1_700_000_000_000_000_000)]);

        $point = $this->onlyPoint($this->data('g', Gauge::class)->dataPoints);

        self::assertInstanceOf(NumberDataPoint::class, $point);
        self::assertSame(1.0, $point->value);
        self::assertSame(1_700_000_000_000_000_001, $point->startTimestamp);
    }

    public function test_distinct_attribute_sets_are_distinct_series(): void
    {
        $this->request(function (MeterInterface $meter) {
            $counter = $meter->createCounter('requests');
            $counter->add(1, ['route' => '/a', 'method' => 'GET']);
            $counter->add(2, ['route' => '/b']);
        });
        $this->request(fn (MeterInterface $meter) => $meter->createCounter('requests')->add(3, ['method' => 'GET', 'route' => '/a']));

        $values = [];
        foreach ($this->data('requests', Sum::class)->dataPoints as $point) {
            $values[json_encode($point->attributes->toArray(), JSON_UNESCAPED_SLASHES)] = $point->value;
        }
        ksort($values);

        self::assertSame(['{"method":"GET","route":"/a"}' => 4, '{"route":"/b"}' => 2], $values);
    }

    public function test_cardinality_overflow_goes_to_the_overflow_series(): void
    {
        $store = new RedisStore($this->client, self::PREFIX, cardinalityLimit: 3);
        $this->request(function (MeterInterface $meter) {
            $counter = $meter->createCounter('requests');
            foreach (['a', 'b', 'c', 'd', 'e'] as $user) {
                $counter->add(1, ['user' => $user]);
            }
        }, $store);
        $this->request(fn (MeterInterface $meter) => $meter->createCounter('requests')->add(10, ['user' => 'a']), $store);

        $values = [];
        foreach ($this->data('requests', Sum::class)->dataPoints as $point) {
            $values[json_encode($point->attributes->toArray(), JSON_UNESCAPED_SLASHES)] = $point->value;
        }

        self::assertCount(4, $values);
        self::assertSame(11, $values['{"user":"a"}']);
        self::assertSame(2, $values['{"otel.metric.overflow":true}']);
    }

    public function test_same_name_with_other_bucket_bounds_is_a_separate_metric(): void
    {
        $this->request(fn (MeterInterface $meter) => $meter->createHistogram('latency', 'ms', null, ['ExplicitBucketBoundaries' => [1, 2]])->record(1.5));
        $this->request(fn (MeterInterface $meter) => $meter->createHistogram('latency', 'ms', null, ['ExplicitBucketBoundaries' => [10, 20, 30]])->record(25));

        $byBounds = [];
        foreach ($this->collect() as $metric) {
            self::assertInstanceOf(Histogram::class, $metric->data);
            $point = $this->onlyPoint($metric->data->dataPoints);
            self::assertInstanceOf(HistogramDataPoint::class, $point);
            $byBounds[json_encode($point->explicitBounds, JSON_PRESERVE_ZERO_FRACTION)] = $point->bucketCounts;
        }
        ksort($byBounds);

        self::assertSame(['[1.0,2.0]' => [0, 1, 0], '[10.0,20.0,30.0]' => [0, 0, 1, 0]], $byBounds);
    }

    public function test_collected_metrics_convert_to_otlp_as_cumulative(): void
    {
        $this->request(function (MeterInterface $meter) {
            $meter->createCounter('requests')->add(1, ['route' => '/']);
            $meter->createUpDownCounter('queue')->add(-1);
            $meter->createHistogram('latency')->record(3.5);
            $meter->createGauge('temperature')->record(21);
        });

        $request = (new MetricConverter)->convert($this->collect());

        $types = [];
        foreach ($request->getResourceMetrics() as $resourceMetrics) {
            foreach ($resourceMetrics->getScopeMetrics() as $scopeMetrics) {
                self::assertSame('test', $scopeMetrics->getScope()?->getName());
                foreach ($scopeMetrics->getMetrics() as $metric) {
                    self::assertInstanceOf(ProtoMetric::class, $metric);
                    $types[$metric->getName()] = $metric->getData();
                    if ($metric->getData() === 'sum') {
                        self::assertSame(AggregationTemporality::AGGREGATION_TEMPORALITY_CUMULATIVE, $metric->getSum()?->getAggregationTemporality());
                    }
                    if ($metric->getData() === 'histogram') {
                        self::assertSame(AggregationTemporality::AGGREGATION_TEMPORALITY_CUMULATIVE, $metric->getHistogram()?->getAggregationTemporality());
                    }
                }
            }
        }
        ksort($types);

        self::assertSame(['latency' => 'histogram', 'queue' => 'sum', 'requests' => 'sum', 'temperature' => 'gauge'], $types);
    }

    public function test_float_values_keep_their_precision(): void
    {
        $this->request(fn (MeterInterface $meter) => $meter->createCounter('bytes')->add(0.1));
        $this->request(fn (MeterInterface $meter) => $meter->createCounter('bytes')->add(0.2));

        $point = $this->onlyPoint($this->data('bytes', Sum::class)->dataPoints);

        self::assertInstanceOf(NumberDataPoint::class, $point);
        self::assertEqualsWithDelta(0.3, $point->value, 1e-15);
    }

    public function test_empty_store_collects_nothing(): void
    {
        self::assertSame([], $this->collect());
    }

    public function test_client_falls_back_to_eval_when_redis_does_not_know_the_script(): void
    {
        $value = uniqid('', true);
        $script = "return '{$value}'";

        self::assertSame($value, $this->client->evalScript($script, [], []));
        self::assertSame($value, $this->client->evalScript($script, [], []));
    }

    public function test_client_throws_on_script_error(): void
    {
        $this->expectException(Exception::class);

        $this->client->evalScript("return redis.error_reply('boom')", [], []);
    }

    public function test_concurrent_requests_give_exact_totals(): void
    {
        $workers = 8;
        $requests = 50;
        $processes = [];
        for ($i = 0; $i < $workers; $i++) {
            $command = [PHP_BINARY, '-dxdebug.mode=off', __DIR__.'/Fixtures/worker.php', $this->clientName(), self::host(), (string) self::port(), self::PREFIX, (string) $requests];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $output);
        }

        $counter = $this->onlyPoint($this->data('requests', Sum::class)->dataPoints);
        $histogram = $this->onlyPoint($this->data('latency', Histogram::class)->dataPoints);

        $total = $workers * $requests;
        self::assertInstanceOf(NumberDataPoint::class, $counter);
        self::assertSame($total, $counter->value);
        self::assertInstanceOf(HistogramDataPoint::class, $histogram);
        self::assertSame($total, $histogram->count);
        // Each worker records request numbers 1..$requests; bounds are [10, 25].
        self::assertSame([10 * $workers, 15 * $workers, 25 * $workers], $histogram->bucketCounts);
        self::assertSame($workers * $requests * ($requests + 1) / 2, $histogram->sum);
    }

    /**
     * Simulates one FPM request: a new provider, recording, then the flush on shutdown.
     *
     * @param  callable(MeterInterface): void  $record
     */
    private function request(callable $record, ?RedisStore $store = null): void
    {
        $provider = (new MeterProviderBuilder)
            ->setResource(ResourceInfoFactory::emptyResource())
            ->addReader(new ExportingReader(new StoreExporter($store ?? $this->store)))
            ->build();

        $record($provider->getMeter('test'));

        self::assertTrue($provider->shutdown());
    }

    /**
     * @return list<Metric>
     */
    private function collect(): array
    {
        return $this->store->collect(ResourceInfoFactory::emptyResource());
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $type
     * @return T
     */
    private function data(string $name, string $type): object
    {
        $found = array_values(array_filter($this->collect(), fn (Metric $metric) => $metric->name === $name));

        self::assertCount(1, $found, "Expected one metric named {$name}.");
        self::assertInstanceOf($type, $found[0]->data);

        return $found[0]->data;
    }

    /**
     * @param  iterable<mixed>  $points
     */
    private function onlyPoint(iterable $points): mixed
    {
        $points = [...$points];
        self::assertCount(1, $points);

        return $points[0];
    }

    private function gauge(float $value, int $timestamp): Metric
    {
        return new Metric(
            new InstrumentationScope('test', null, null, Attributes::create([])),
            ResourceInfoFactory::emptyResource(),
            'g',
            null,
            null,
            new Gauge([new NumberDataPoint($value, Attributes::create([]), $timestamp, $timestamp)]),
        );
    }
}
