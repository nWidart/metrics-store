<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore;

use Nwidart\MetricsStore\Redis\RedisClient;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Attribute\AttributesInterface;
use OpenTelemetry\SDK\Common\Instrumentation\InstrumentationScope;
use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use UnexpectedValueException;

/**
 * Keeps cumulative OpenTelemetry metrics in Redis.
 *
 * Layout, all keys under the prefix:
 *   meta                 hash, metric id => metric description (JSON)
 *   idx:{id}             set of the series keys of one metric
 *   s:{id}:{seriesKey}   hash of one series; a histogram series holds field b{i} per non-empty bucket
 *
 * Histograms keep bucket counts and the sum, not min and max: a min or max since the store
 * started says little, Prometheus ignores both, and OTLP makes them optional. Comparing them
 * cost about a fifth of the merge time.
 *
 * @phpstan-type MetaArray array{name: string, unit: ?string, description: ?string, kind: string, monotonic: bool, bounds: list<float>, scope: array{name: string, version: ?string, schemaUrl: ?string}}
 */
final class RedisStore
{
    private const OVERFLOW_KEY = 'overflow';

    private const OVERFLOW_ATTRIBUTES = '{"otel.metric.overflow":true}';

    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * KEYS[1] = meta key, ARGV[1] = prefix, ARGV[2] = cardinality limit, then one block per metric:
     *   id, meta JSON, kind (s = sum, h = histogram, g = gauge), point count, then per point:
     *   sum        series key, attributes JSON, start timestamp, value field, value
     *   gauge      series key, attributes JSON, timestamp, value
     *   histogram  series key, attributes JSON, start timestamp, sum, bucket pair count,
     *              then (bucket index, count) pairs
     * Numbers arrive as strings, because Lua numbers are doubles.
     *
     * A series key that SADD adds is a new series: only then are its attributes, start timestamp and
     * the metric's meta written. The histogram count is not stored: it is the sum of the buckets.
     * Integer sum values go to field vi with HINCRBY, float values to field v with HINCRBYFLOAT.
     */
    private const MERGE_SCRIPT = <<<'LUA'
        local call = redis.call
        local p = ARGV[1]
        local limit = tonumber(ARGV[2])
        local n = #ARGV
        local i = 3

        -- Compares two non-negative integer strings without converting them to doubles.
        local function not_older(new, old)
            if #new ~= #old then return #new > #old end
            return new >= old
        end


        while i <= n do
            local id, meta, kind, count = ARGV[i], ARGV[i + 1], ARGV[i + 2], tonumber(ARGV[i + 3])
            i = i + 4
            local idx = p .. 'idx:' .. id
            local base = p .. 's:' .. id .. ':'
            for _ = 1, count do
                local sk, a, ts = ARGV[i], ARGV[i + 1], ARGV[i + 2]
                i = i + 3
                local fresh = call('SADD', idx, sk) == 1
                if fresh then
                    if sk ~= 'overflow' and call('SCARD', idx) > limit then
                        call('SREM', idx, sk)
                        sk, a = 'overflow', '{"otel.metric.overflow":true}'
                        fresh = call('SADD', idx, sk) == 1
                    end
                    call('HSETNX', KEYS[1], id, meta)
                end
                local key = base .. sk

                if kind == 's' then
                    if fresh then call('HSET', key, 'a', a, 'st', ts) end
                    if ARGV[i] == 'vi' then
                        call('HINCRBY', key, 'vi', ARGV[i + 1])
                    else
                        call('HINCRBYFLOAT', key, 'v', ARGV[i + 1])
                    end
                    i = i + 2
                elseif kind == 'h' then
                    local sum, nb = ARGV[i], tonumber(ARGV[i + 1])
                    i = i + 2
                    if fresh then call('HSET', key, 'a', a, 'st', ts) end
                    call('HINCRBYFLOAT', key, 's', sum)
                    for _ = 1, nb do
                        call('HINCRBY', key, 'b' .. ARGV[i], ARGV[i + 1])
                        i = i + 2
                    end
                else
                    local v = ARGV[i]
                    i = i + 1
                    if fresh then
                        call('HSET', key, 'a', a, 'v', v, 't', ts)
                    else
                        local t = call('HGET', key, 't')
                        if not t or not_older(ts, t) then call('HSET', key, 'v', v, 't', ts) end
                    end
                end
            end
        end
        return 1
        LUA;

    /** Returns {{id, meta, {series hash, ...}}, ...}. */
    private const COLLECT_SCRIPT = <<<'LUA'
        local p = ARGV[1]
        local meta = redis.call('HGETALL', KEYS[1])
        local out = {}
        for i = 1, #meta, 2 do
            local id = meta[i]
            local series = {}
            for _, sk in ipairs(redis.call('SMEMBERS', p .. 'idx:' .. id)) do
                series[#series + 1] = redis.call('HGETALL', p .. 's:' .. id .. ':' .. sk)
            end
            out[#out + 1] = {id, meta[i + 1], series}
        end
        return out
        LUA;

    /** Every key the merge script writes is reachable from the meta hash. */
    private const CLEAR_SCRIPT = <<<'LUA'
        local p = ARGV[1]
        local ids = redis.call('HKEYS', KEYS[1])
        for _, id in ipairs(ids) do
            local idx = p .. 'idx:' .. id
            for _, sk in ipairs(redis.call('SMEMBERS', idx)) do
                redis.call('DEL', p .. 's:' .. id .. ':' .. sk)
            end
            redis.call('DEL', idx)
        end
        redis.call('DEL', KEYS[1])
        return #ids
        LUA;

    public function __construct(
        private readonly RedisClient $client,
        private readonly string $prefix = '{metrics}:',
        private readonly int $cardinalityLimit = 2000,
    ) {}

    /**
     * Adds the delta data of one collection to the stored cumulative data, in one round trip.
     *
     * @param  iterable<Metric>  $batch
     */
    public function merge(iterable $batch): void
    {
        $args = [];
        foreach ($batch as $metric) {
            array_push($args, ...$this->mergeArgs($metric));
        }

        if ($args === []) {
            return;
        }

        $this->client->evalScript(
            self::MERGE_SCRIPT,
            [$this->prefix.'meta'],
            [$this->prefix, (string) $this->cardinalityLimit, ...$args],
        );
    }

    /**
     * Reads every stored series as cumulative data, in one round trip.
     *
     * @return list<Metric>
     */
    public function collect(ResourceInfo $resource): array
    {
        $rows = $this->client->evalScript(self::COLLECT_SCRIPT, [$this->prefix.'meta'], [$this->prefix]);
        $now = Clock::getDefault()->now();

        $metrics = [];
        foreach (self::list($rows) as $row) {
            [, $metaJson, $series] = self::list($row) + [null, null, null];
            $meta = self::meta(self::string($metaJson));

            $hashes = [];
            foreach (self::list($series) as $flat) {
                $hashes[] = self::hash($flat);
            }
            if ($hashes === []) {
                continue;
            }

            $metrics[] = new Metric(
                new InstrumentationScope($meta['scope']['name'], $meta['scope']['version'], $meta['scope']['schemaUrl'], Attributes::create([])),
                $resource,
                $meta['name'],
                $meta['unit'],
                $meta['description'],
                match ($meta['kind']) {
                    'sum' => new Sum(array_map(fn (array $h) => self::sumPoint($h, $now), $hashes), Temporality::CUMULATIVE, $meta['monotonic']),
                    'histogram' => new Histogram(array_map(fn (array $h) => self::histogramPoint($h, $meta['bounds'], $now), $hashes), Temporality::CUMULATIVE),
                    'gauge' => new Gauge(array_map(fn (array $h) => self::gaugePoint($h, $now), $hashes)),
                    default => throw new UnexpectedValueException("Unknown metric kind {$meta['kind']}."),
                },
            );
        }

        return $metrics;
    }

    /** Deletes every key under the prefix. */
    public function clear(): void
    {
        $this->client->evalScript(self::CLEAR_SCRIPT, [$this->prefix.'meta'], [$this->prefix]);
    }

    /**
     * The merge script arguments of one metric, in the layout MERGE_SCRIPT documents.
     *
     * @return list<string> empty when the metric holds no usable point
     */
    private function mergeArgs(Metric $metric): array
    {
        $data = $metric->data;
        if ($data instanceof Sum) {
            [$kind, $monotonic] = ['sum', $data->monotonic];
        } elseif ($data instanceof Histogram) {
            [$kind, $monotonic] = ['histogram', false];
        } elseif ($data instanceof Gauge) {
            [$kind, $monotonic] = ['gauge', false];
        } else {
            return [];
        }

        $bounds = [];
        $points = [];
        $count = 0;
        foreach ($data->dataPoints as $point) {
            if ($point instanceof HistogramDataPoint) {
                $bounds = array_map(floatval(...), $point->explicitBounds);
                $args = self::histogramArgs($point);
            } else {
                $args = self::numberArgs($point, $kind);
            }
            if ($args !== []) {
                array_push($points, ...$args);
                $count++;
            }
        }
        if ($count === 0) {
            return [];
        }

        $scope = $metric->instrumentationScope;
        $meta = [
            'name' => $metric->name,
            'unit' => $metric->unit,
            'description' => $metric->description,
            'kind' => $kind,
            'monotonic' => $monotonic,
            'bounds' => $bounds,
            'scope' => ['name' => $scope->getName(), 'version' => $scope->getVersion(), 'schemaUrl' => $scope->getSchemaUrl()],
        ];
        $identity = [$meta['scope'], $metric->name, $metric->unit, $kind, $monotonic, $bounds];

        return [
            hash('xxh128', json_encode($identity, self::JSON_FLAGS | JSON_PRESERVE_ZERO_FRACTION)),
            json_encode($meta, self::JSON_FLAGS | JSON_PRESERVE_ZERO_FRACTION),
            $kind[0],
            (string) $count,
            ...$points,
        ];
    }

    /**
     * @return list<string> empty when the point holds no usable value
     */
    private static function histogramArgs(HistogramDataPoint $point): array
    {
        if ($point->count === 0 || ! is_finite($point->sum)) {
            return [];
        }

        // Only non-zero buckets, as index, count pairs.
        $buckets = [];
        foreach ($point->bucketCounts as $i => $count) {
            if ($count !== 0) {
                $buckets[] = (string) $i;
                $buckets[] = (string) $count;
            }
        }

        return [
            ...self::series($point->attributes->toArray()),
            (string) $point->startTimestamp,
            self::number($point->sum),
            (string) (count($buckets) / 2),
            ...$buckets,
        ];
    }

    /**
     * @return list<string> empty when the point holds no usable value
     */
    private static function numberArgs(mixed $point, string $kind): array
    {
        // HINCRBYFLOAT rejects NAN and INF; an error would abort the script halfway.
        if (! $point instanceof NumberDataPoint || ! is_finite($point->value)) {
            return [];
        }

        $series = self::series($point->attributes->toArray());
        if ($kind === 'gauge') {
            return [...$series, (string) $point->timestamp, self::number($point->value)];
        }

        return [...$series, (string) $point->startTimestamp, is_int($point->value) ? 'vi' : 'v', self::number($point->value)];
    }

    /**
     * Series key and attributes JSON of one attribute set.
     *
     * @param  array<array-key, mixed>  $attributes
     * @return array{string, string}
     */
    private static function series(array $attributes): array
    {
        ksort($attributes);
        $json = json_encode($attributes, self::JSON_FLAGS | JSON_PRESERVE_ZERO_FRACTION);

        return [$json === self::OVERFLOW_ATTRIBUTES ? self::OVERFLOW_KEY : hash('xxh128', $json), $json];
    }

    /** Formats a finite number so that Redis and Lua read it without precision loss. */
    private static function number(float|int $value): string
    {
        return is_int($value) ? (string) $value : var_export($value, true);
    }

    /** Integer-looking strings that fit in an int become ints, the rest floats. */
    private static function parseNumber(string $value): float|int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);

        return $int !== false && (string) $int === $value ? $int : (float) $value;
    }

    /**
     * @param  array<string, string>  $hash
     */
    private static function sumPoint(array $hash, int $now): NumberDataPoint
    {
        // Integer values add up exactly in vi, float values in v.
        $value = match (true) {
            isset($hash['vi'], $hash['v']) => (int) $hash['vi'] + self::parseNumber($hash['v']),
            isset($hash['vi']) => (int) $hash['vi'],
            default => self::parseNumber(self::field($hash, 'v')),
        };

        return new NumberDataPoint(
            $value,
            self::attributes($hash),
            (int) self::field($hash, 'st'),
            $now,
        );
    }

    /**
     * @param  array<string, string>  $hash
     */
    private static function gaugePoint(array $hash, int $now): NumberDataPoint
    {
        return new NumberDataPoint(
            self::parseNumber(self::field($hash, 'v')),
            self::attributes($hash),
            (int) self::field($hash, 't'),
            $now,
        );
    }

    /**
     * @param  array<string, string>  $hash
     * @param  list<float>  $bounds
     */
    private static function histogramPoint(array $hash, array $bounds, int $now): HistogramDataPoint
    {
        $buckets = [];
        for ($i = 0, $n = count($bounds); $i <= $n; $i++) {
            $buckets[] = (int) ($hash["b{$i}"] ?? 0);
        }

        return new HistogramDataPoint(
            array_sum($buckets),
            self::parseNumber(self::field($hash, 's')),
            NAN,
            NAN,
            $buckets,
            $bounds,
            self::attributes($hash),
            (int) self::field($hash, 'st'),
            $now,
        );
    }

    /**
     * @param  array<string, string>  $hash
     * @return AttributesInterface&iterable<string, mixed>
     */
    private static function attributes(array $hash): AttributesInterface
    {
        $attributes = json_decode(self::field($hash, 'a'), true, flags: JSON_THROW_ON_ERROR);

        return Attributes::create(is_array($attributes) ? $attributes : []);
    }

    /**
     * @param  array<string, string>  $hash
     */
    private static function field(array $hash, string $field): string
    {
        return $hash[$field] ?? throw new UnexpectedValueException("Series field {$field} is missing.");
    }

    /**
     * @return MetaArray
     */
    private static function meta(string $json): array
    {
        /** @var MetaArray $meta */
        $meta = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return $meta;
    }

    /**
     * Turns a flat HGETALL reply into a field => value map.
     *
     * @return array<string, string>
     */
    private static function hash(mixed $flat): array
    {
        $hash = [];
        foreach (array_chunk(self::list($flat), 2) as $pair) {
            $hash[self::string($pair[0])] = self::string($pair[1] ?? null);
        }

        return $hash;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : throw new UnexpectedValueException('Redis returned an unexpected reply.');
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new UnexpectedValueException('Redis returned an unexpected reply.');
    }
}
