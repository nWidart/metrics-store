<?php

declare(strict_types=1);

/*
 * How RedisStore scales with the number of metrics and series. Needs ext-redis and a Redis server.
 *
 *   php -d opcache.enable_cli=1 -d memory_limit=4G benchmarks/scale.php [merge|collect|all]
 *
 * Run it without Xdebug, and close to Redis: through the Docker Desktop port forward, the round
 * trip times are mostly the forward.
 *
 * merge    one request records one value into each of M instruments and flushes it through
 *          StoreExporter, like an FPM request. Reports the PHP time to record and to flush, the round
 *          trip and the Redis CPU of the one script call, and the payload. "cold" is the Redis CPU of
 *          the first merge into an empty store, where every series is new; the other columns are the
 *          steady state.
 * collect  the store holds M metrics with S series each. Reports collect(), the Prometheus text
 *          rendering, the Redis calls per collect, the longest one, and the Redis memory per series.
 *
 * Environment: REDIS_HOST (default 127.0.0.1), REDIS_PORT (default 6379). Uses database 15 and the
 * key prefix {scalebench}:, which it clears.
 */

use Nwidart\MetricsStore\Prometheus\PrometheusTextFormat;
use Nwidart\MetricsStore\Redis\PhpRedisClient;
use Nwidart\MetricsStore\Redis\RedisClient;
use Nwidart\MetricsStore\RedisStore;
use Nwidart\MetricsStore\StoreExporter;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;

// The package's own vendor/, or the one of a workspace that holds the package under packages/.
require is_file(__DIR__.'/../vendor/autoload.php') ? __DIR__.'/../vendor/autoload.php' : __DIR__.'/../../../vendor/autoload.php';

const BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10];
const PREFIX = '{scalebench}:';

$mode = $argv[1] ?? 'all';

$redis = new Redis;
$redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 6379));
$redis->select(15);

// Times every script call, and keeps the argument bytes of the last one.
$client = new class(new PhpRedisClient($redis)) implements RedisClient
{
    public int $calls = 0;

    public float $wallNs = 0;

    public float $maxNs = 0;

    public int $bytes = 0;

    public function __construct(private readonly RedisClient $inner) {}

    public function evalScript(string $script, array $keys, array $args): mixed
    {
        $this->calls++;
        $this->bytes = array_sum(array_map(strlen(...), $args));
        $start = hrtime(true);
        $result = $this->inner->evalScript($script, $keys, $args);
        $ns = hrtime(true) - $start;
        $this->wallNs += $ns;
        $this->maxNs = max($this->maxNs, $ns);

        return $result;
    }

    public function reset(): void
    {
        $this->calls = 0;
        $this->wallNs = 0;
        $this->maxNs = 0;
    }
};
$store = new RedisStore($client, PREFIX);

/** Redis CPU of EVALSHA and EVAL since the last RESETSTAT: [calls, total µs]. */
function redisCpu(Redis $redis): array
{
    $calls = $usec = 0;
    foreach ($redis->info('commandstats') as $name => $line) {
        if (in_array($name, ['cmdstat_evalsha', 'cmdstat_eval'], true) && preg_match('/calls=(\d+),usec=(\d+)/', (string) $line, $m)) {
            $calls += (int) $m[1];
            $usec += (int) $m[2];
        }
    }

    return [$calls, $usec];
}

/**
 * Records one value into each of $metrics instruments, $series attribute sets each.
 * Kinds: counter, histogram, or mixed (half counters, a quarter histograms, a quarter gauges).
 */
function record(MeterInterface $meter, string $kind, int $metrics, int $seriesFrom, int $seriesTo): void
{
    for ($m = 0; $m < $metrics; $m++) {
        $k = $kind === 'mixed' ? ['counter', 'counter', 'histogram', 'gauge'][$m % 4] : $kind;
        $name = "app.metric.{$m}";
        $instrument = match ($k) {
            'counter' => $meter->createCounter($name, '{item}'),
            'histogram' => $meter->createHistogram($name, 's', advisory: ['ExplicitBucketBoundaries' => BUCKETS]),
            'gauge' => $meter->createGauge($name, '{item}'),
        };
        for ($s = $seriesFrom; $s < $seriesTo; $s++) {
            $attributes = ['app.route' => "/route/{$s}", 'app.status' => 200];
            match ($k) {
                'counter' => $instrument->add(1, $attributes),
                'histogram' => $instrument->record(mt_rand(1_000, 2_000_000) / 1e6, $attributes),
                'gauge' => $instrument->record(mt_rand(0, 1000), $attributes),
            };
        }
    }
}

/** One FPM-like request: a fresh provider, M instruments, one flush. Returns [record ns, flush ns]. */
function request(RedisStore $store, string $kind, int $metrics, int $seriesFrom = 0, int $seriesTo = 1): array
{
    $provider = (new MeterProviderBuilder)
        ->setResource(ResourceInfoFactory::emptyResource())
        ->addReader(new ExportingReader(new StoreExporter($store)))
        ->build();
    $meter = $provider->getMeter('bench');

    $start = hrtime(true);
    record($meter, $kind, $metrics, $seriesFrom, $seriesTo);
    $recorded = hrtime(true);
    if (! $provider->shutdown()) {
        throw new RuntimeException('Flush failed.');
    }

    return [$recorded - $start, hrtime(true) - $recorded];
}

function benchMerge(Redis $redis, RedisStore $store, object $client): void
{
    printf("MERGE: one request records 1 value into each of M metrics, 1 series each\n");
    printf("%-9s %5s %7s | %9s %9s | %9s %9s %9s | %9s %8s\n", 'kind', 'M', 'iters', 'record', 'flush', 'rtt', 'redis', 'cold', 'payload', 'B/metric');
    printf("%-9s %5s %7s | %9s %9s | %9s %9s %9s | %9s %8s\n", '', '', '', 'µs', 'µs', 'µs', 'µs', 'µs', 'KB', '');

    foreach (['counter', 'histogram', 'mixed'] as $kind) {
        foreach ([10, 100, 250, 500, 1000, 2000] as $metrics) {
            $store->clear();

            // Cold: the first merge creates every series.
            $redis->config('RESETSTAT');
            request($store, $kind, $metrics);
            [, $coldUs] = redisCpu($redis);

            $iterations = max(30, min(2000, intdiv(40_000, $metrics)));
            for ($i = 0; $i < min(50, $iterations); $i++) {
                request($store, $kind, $metrics);
            }

            $redis->config('RESETSTAT');
            $client->reset();
            $recordNs = $flushNs = 0;
            for ($i = 0; $i < $iterations; $i++) {
                [$r, $f] = request($store, $kind, $metrics);
                $recordNs += $r;
                $flushNs += $f;
            }
            [$calls, $usec] = redisCpu($redis);

            printf(
                "%-9s %5d %7d | %9.0f %9.0f | %9.0f %9.0f %9d | %9.1f %8.0f\n",
                $kind, $metrics, $iterations,
                $recordNs / 1e3 / $iterations,
                $flushNs / 1e3 / $iterations,
                $client->wallNs / 1e3 / $client->calls,
                $usec / max($calls, 1),
                $coldUs,
                $client->bytes / 1024,
                $client->bytes / $metrics,
            );
        }
        echo "\n";
    }
}

function benchCollect(Redis $redis, RedisStore $store, object $client): void
{
    printf("COLLECT: the store holds M metrics (mixed kinds) with S series each\n");
    printf("%5s %5s %8s | %9s %9s %9s | %6s %9s %9s | %9s %9s\n", 'M', 'S', 'series', 'collect', 'render', 'total', 'calls', 'max call', 'redis', 'text', 'mem/series');
    printf("%5s %5s %8s | %9s %9s %9s | %6s %9s %9s | %9s %9s\n", '', '', '', 'ms', 'ms', 'ms', '', 'ms', 'ms', 'KB', 'B');

    $format = new PrometheusTextFormat;
    $resource = ResourceInfoFactory::emptyResource();

    foreach ([[100, 1], [100, 10], [100, 100], [500, 10], [1000, 1], [1000, 10], [1000, 100]] as [$metrics, $series]) {
        $store->clear();
        $before = (int) $redis->info('memory')['used_memory'];
        // Populate in merges of at most about 10,000 points.
        $slice = max(1, intdiv(10_000, $metrics));
        for ($from = 0; $from < $series; $from += $slice) {
            request($store, 'mixed', $metrics, $from, min($from + $slice, $series));
        }
        $after = (int) $redis->info('memory')['used_memory'];

        $runs = $metrics * $series >= 50_000 ? 2 : 5;
        $collectNs = $renderNs = 0;
        $text = '';
        $redis->config('RESETSTAT');
        $client->reset();
        for ($i = 0; $i < $runs; $i++) {
            $start = hrtime(true);
            $collected = $store->collect($resource);
            $mid = hrtime(true);
            $text = $format->render($collected);
            $collectNs += $mid - $start;
            $renderNs += hrtime(true) - $mid;
        }
        [$calls, $usec] = redisCpu($redis);

        printf(
            "%5d %5d %8d | %9.1f %9.1f %9.1f | %6d %9.2f %9.1f | %9.0f %9.0f\n",
            $metrics, $series, $metrics * $series,
            $collectNs / 1e6 / $runs,
            $renderNs / 1e6 / $runs,
            ($collectNs + $renderNs) / 1e6 / $runs,
            $client->calls / $runs,
            $client->maxNs / 1e6,
            $usec / 1e3 / $runs,
            strlen($text) / 1024,
            ($after - $before) / ($metrics * $series),
        );
    }
}

printf("PHP %s, opcache %s, Redis %s\n\n", PHP_VERSION, ini_get('opcache.enable_cli') ? 'on' : 'off', $redis->info('server')['redis_version']);

if ($mode === 'merge' || $mode === 'all') {
    benchMerge($redis, $store, $client);
}
if ($mode === 'collect' || $mode === 'all') {
    benchCollect($redis, $store, $client);
}

$store->clear();
