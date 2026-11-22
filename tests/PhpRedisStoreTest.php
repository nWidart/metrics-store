<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore\Tests;

use Nwidart\MetricsStore\Redis\PhpRedisClient;
use Nwidart\MetricsStore\Redis\RedisClient;
use Redis;
use RedisException;

/**
 * Runs every store test again with the phpredis client. Skipped when ext-redis is not loaded.
 */
final class PhpRedisStoreTest extends RedisStoreTest
{
    protected function client(): RedisClient
    {
        if (! extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is not loaded.');
        }

        $redis = new Redis;
        try {
            $redis->connect(self::host(), self::port(), 0.5);
        } catch (RedisException $e) {
            self::markTestSkipped(sprintf('Redis is not reachable at %s:%d (%s). Run `docker compose up -d redis`.', self::host(), self::port(), $e->getMessage()));
        }
        $redis->select(15);

        return new PhpRedisClient($redis);
    }

    protected function clientName(): string
    {
        return 'phpredis';
    }
}
