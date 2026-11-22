<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore\Tests;

use Nwidart\MetricsStore\Redis\PredisClient;
use Nwidart\MetricsStore\RedisStore;
use Nwidart\MetricsStore\StoreExporter;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Metrics\InstrumentType;
use OpenTelemetry\SDK\Metrics\MeterProviderBuilder;
use OpenTelemetry\SDK\Metrics\MetricMetadataInterface;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Connection\ConnectionException;

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

    public function test_force_flush_merges_and_the_next_flush_adds_only_the_new_delta(): void
    {
        $client = new Client(['host' => getenv('REDIS_HOST') ?: '127.0.0.1', 'port' => (int) (getenv('REDIS_PORT') ?: 6379), 'database' => 15, 'timeout' => 0.5]);
        try {
            $client->ping();
        } catch (ConnectionException) {
            self::markTestSkipped('Redis is not reachable. Run `docker compose up -d redis`.');
        }
        $store = new RedisStore(new PredisClient($client), '{exporter-test}:');
        $store->clear();
        $provider = (new MeterProviderBuilder)
            ->setResource(ResourceInfoFactory::emptyResource())
            ->addReader(new ExportingReader(new StoreExporter($store)))
            ->build();
        $counter = $provider->getMeter('test')->createCounter('jobs');

        $counter->add(3);
        self::assertTrue($provider->forceFlush());
        $counter->add(2);
        self::assertTrue($provider->forceFlush());

        $data = $store->collect(ResourceInfoFactory::emptyResource())[0]->data;
        $store->clear();
        self::assertInstanceOf(Sum::class, $data);
        self::assertSame(5, $data->dataPoints[0]->value);
    }

    public function test_an_empty_batch_needs_no_redis(): void
    {
        $store = new RedisStore(new PredisClient(new Client(['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2])));

        self::assertTrue((new StoreExporter($store))->export([]));
    }

    public function test_synchronous_instruments_use_delta_temporality(): void
    {
        $exporter = new StoreExporter(new RedisStore(new PredisClient(new Client)));

        foreach ([InstrumentType::COUNTER, InstrumentType::UP_DOWN_COUNTER, InstrumentType::HISTOGRAM, InstrumentType::GAUGE] as $type) {
            self::assertSame(Temporality::DELTA, $exporter->temporality(self::metadata($type)), $type);
        }
    }

    public function test_observable_instruments_are_not_exported(): void
    {
        $exporter = new StoreExporter(new RedisStore(new PredisClient(new Client)));

        foreach ([InstrumentType::ASYNCHRONOUS_COUNTER, InstrumentType::ASYNCHRONOUS_UP_DOWN_COUNTER, InstrumentType::ASYNCHRONOUS_GAUGE] as $type) {
            self::assertNull($exporter->temporality(self::metadata($type)), $type);
        }
    }

    private static function metadata(string $instrumentType): MetricMetadataInterface
    {
        $metadata = self::createStub(MetricMetadataInterface::class);
        $metadata->method('instrumentType')->willReturn($instrumentType);

        return $metadata;
    }
}
