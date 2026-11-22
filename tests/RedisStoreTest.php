<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore\Tests;

use Exception;
use Nwidart\MetricsStore\MetricStore;
use Nwidart\MetricsStore\Redis\PredisClient;
use Nwidart\MetricsStore\Redis\RedisClient;
use Nwidart\MetricsStore\RedisStore;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Predis\Client;
use Predis\Connection\ConnectionException;

/**
 * Runs against a real Redis, database 15, prefix {test}:.
 * Start it with `docker compose up -d redis`. Set REDIS_HOST and REDIS_PORT to use another server.
 */
class RedisStoreTest extends MetricStoreTestCase
{
    protected const PREFIX = '{test}:';

    private RedisClient $client;

    protected function setUp(): void
    {
        $this->client = $this->client();
        $this->store = $this->newStore();
        $this->store->clear();
    }

    protected function tearDown(): void
    {
        // Not set when setUp() skipped the test.
        if (isset($this->store)) {
            $this->store->clear();
        }
    }

    protected function newStore(int $cardinalityLimit = 2000): MetricStore
    {
        return new RedisStore($this->client, self::PREFIX, $cardinalityLimit);
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

    /** A client whose own key prefix applies to every key it sends. */
    protected function prefixedClient(string $prefix): RedisClient
    {
        return new PredisClient(new Client(['host' => self::host(), 'port' => self::port(), 'database' => 15], ['prefix' => $prefix]));
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

    public function test_a_client_key_prefix_applies_to_every_key(): void
    {
        $raw = new Client(['host' => self::host(), 'port' => self::port(), 'database' => 15]);
        $raw->del(...($raw->keys('clientprefix:*') ?: ['clientprefix:none']));
        $store = new RedisStore($this->prefixedClient('clientprefix:'), self::PREFIX);

        $this->request(function (MeterInterface $meter) {
            $meter->createCounter('requests')->add(2, ['route' => '/']);
            $meter->createHistogram('latency')->record(3.5);
        }, $store);

        $keys = $raw->keys('*'.self::PREFIX.'*');
        self::assertNotEmpty($keys);
        foreach ($keys as $key) {
            self::assertStringStartsWith('clientprefix:'.self::PREFIX, $key);
        }
        $names = array_map(fn (Metric $metric) => $metric->name, $store->collect(ResourceInfoFactory::emptyResource()));
        sort($names);
        self::assertSame(['latency', 'requests'], $names);

        $store->clear();
        self::assertSame([], $raw->keys('clientprefix:*'));
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
}
