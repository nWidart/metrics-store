<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore\Tests;

use Nwidart\MetricsStore\InMemoryStore;
use Nwidart\MetricsStore\MetricStore;
use OpenTelemetry\API\Metrics\MeterInterface;
use OpenTelemetry\SDK\Metrics\Data\Sum;

final class InMemoryStoreTest extends MetricStoreTestCase
{
    protected function setUp(): void
    {
        $this->store = $this->newStore();
    }

    protected function newStore(int $cardinalityLimit = 2000): MetricStore
    {
        return new InMemoryStore($cardinalityLimit);
    }

    public function test_the_state_survives_serialization_into_another_store(): void
    {
        $this->request(fn (MeterInterface $meter) => $meter->createCounter('requests')->add(2));
        self::assertInstanceOf(InMemoryStore::class, $this->store);

        $this->store = new InMemoryStore(2000, unserialize(serialize($this->store->state())));
        $this->request(fn (MeterInterface $meter) => $meter->createCounter('requests')->add(3));

        $point = $this->onlyPoint($this->data('requests', Sum::class)->dataPoints);
        self::assertSame(5, $point->value);
    }
}
