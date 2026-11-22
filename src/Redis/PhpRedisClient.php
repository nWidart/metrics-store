<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore\Redis;

use Redis;
use RuntimeException;

final class PhpRedisClient implements RedisClient
{
    public function __construct(private readonly Redis $redis) {}

    public function evalScript(string $script, array $keys, array $args): mixed
    {
        $arguments = [...$keys, ...$args];

        $this->redis->clearLastError();
        $result = $this->redis->evalSha(sha1($script), $arguments, count($keys));
        $error = $this->redis->getLastError();

        if ($result === false && is_string($error) && str_starts_with($error, 'NOSCRIPT')) {
            $this->redis->clearLastError();
            $result = $this->redis->eval($script, $arguments, count($keys));
            $error = $this->redis->getLastError();
        }

        if ($result === false && is_string($error)) {
            throw new RuntimeException($error);
        }

        return $result;
    }
}
