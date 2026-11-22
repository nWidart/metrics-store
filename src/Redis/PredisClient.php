<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore\Redis;

use Predis\ClientInterface;
use Predis\Response\ErrorInterface;
use Predis\Response\ServerException;
use RuntimeException;

final class PredisClient implements RedisClient
{
    public function __construct(private readonly ClientInterface $client) {}

    public function evalScript(string $script, array $keys, array $args): mixed
    {
        try {
            $result = $this->client->evalsha(sha1($script), count($keys), ...$keys, ...$args);
        } catch (ServerException $e) {
            if ($e->getErrorType() !== 'NOSCRIPT') {
                throw $e;
            }
            $result = $this->client->eval($script, count($keys), ...$keys, ...$args);
        }

        // With the "exceptions" option off, predis returns errors instead of throwing them.
        if ($result instanceof ErrorInterface && $result->getErrorType() === 'NOSCRIPT') {
            $result = $this->client->eval($script, count($keys), ...$keys, ...$args);
        }
        if ($result instanceof ErrorInterface) {
            throw new RuntimeException($result->getMessage());
        }

        return $result;
    }
}
