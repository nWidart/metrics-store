<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore\Redis;

interface RedisClient
{
    /**
     * Runs a Lua script with EVALSHA, and falls back to EVAL when Redis does not know the script.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $args
     */
    public function evalScript(string $script, array $keys, array $args): mixed;
}
