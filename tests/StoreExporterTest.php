<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore\Tests;

use Nwidart\MetricsStore\Redis\PredisClient;
use Nwidart\MetricsStore\RedisStore;
use Nwidart\MetricsStore\StoreExporter;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use PHPUnit\Framework\TestCase;
use Predis\Client;

final class StoreExporterTest extends TestCase
{
    public function test_a_redis_failure_returns_false_and_does_not_throw(): void
    {
        // Nothing listens on port 1.
        $store = new RedisStore(new PredisClient(new Client(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2])));
        $exporter = new StoreExporter($store);

        $provider = (new MeterProviderBuilder)
            ->setResource(ResourceInfoFactory::emptyResource())
            ->addReader(new ExportingReader($exporter))
            ->build();
        $provider->getMeter('test')->createCounter('requests')->add(1);

        self::assertFalse($provider->shutdown());
    }

    public function test_an_empty_batch_needs_no_redis(): void
    {
        $store = new RedisStore(new PredisClient(new Client(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2])));

        self::assertTrue((new StoreExporter($store))->export([]));
    }

    public function test_every_metric_uses_delta_temporality(): void
    {
        $exporter = new StoreExporter(new RedisStore(new PredisClient(new Client)));

        self::assertSame(Temporality::DELTA, $exporter->temporality(self::createStub(MetricMetadataInterface::class)));
    }
}
