<?php

declare(strict_types=1);

// One simulated FPM worker for the concurrency test: runs N requests, each with its own provider and flush.
// Usage: php worker.php <predis|phpredis> <host> <port> <prefix> <requests>

use Nwidart\MetricsStore\Redis\PhpRedisClient;
use Nwidart\MetricsStore\Redis\PredisClient;
use Nwidart\MetricsStore\RedisStore;
use Nwidart\MetricsStore\StoreExporter;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use Predis\Client;

require __DIR__.'/../../../../vendor/autoload.php';

[, $clientName, $host, $port, $prefix, $requests] = $argv;

if ($clientName === 'phpredis') {
    $redis = new Redis;
    $redis->connect($host, (int) $port);
    $redis->select(15);
    $client = new PhpRedisClient($redis);
} else {
    $client = new PredisClient(new Client(['host' => $host, 'port' => (int) $port, 'database' => 15]));
}
$store = new RedisStore($client, $prefix);

for ($i = 1; $i <= (int) $requests; $i++) {
    $provider = (new MeterProviderBuilder)
        ->setResource(ResourceInfoFactory::emptyResource())
        ->addReader(new ExportingReader(new StoreExporter($store)))
        ->build();
    $meter = $provider->getMeter('test');
    $meter->createCounter('requests')->add(1);
    $meter->createHistogram('latency', null, null, ['ExplicitBucketBoundaries' => [10, 25]])->record($i);

    if (! $provider->shutdown()) {
        fwrite(STDERR, "Flush failed in request {$i}.\n");
        exit(1);
    }
}
