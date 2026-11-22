<?php

declare(strict_types=1);

namespace Nwidart\MetricsStore\Prometheus;

use OpenTelemetry\SDK\Metrics\Data\Gauge;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use OpenTelemetry\SDK\Metrics\Data\HistogramDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\NumberDataPoint;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\Data\Temporality;

/**
 * Renders cumulative OpenTelemetry metrics in the Prometheus text exposition format, version 0.0.4,
 * with the names and labels the OpenTelemetry "Prometheus and OpenMetrics compatibility"
 * specification defines:
 *   - invalid characters become "_", the unit is appended as a word ("s" becomes "_seconds"),
 *     and a monotonic sum ends in "_total";
 *   - a monotonic sum is a counter, a non-monotonic sum or a gauge is a gauge, and a histogram is
 *     a histogram with cumulative "le" buckets;
 *   - attributes become labels, and otel_scope_name and otel_scope_version name the instrumentation
 *     scope, so that two scopes with the same metric name stay apart. Labels are sorted by name,
 *     with "le" last.
 * Delta metrics are left out: Prometheus reads cumulative values only. A metric whose name another
 * metric of another type already uses is left out too, and so is a repeated sample, because
 * Prometheus rejects the whole scrape for either.
 */
final class PrometheusTextFormat
{
    public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    /** OpenTelemetry (UCUM) unit => Prometheus unit word. */
    private const UNITS = [
        'd' => 'days', 'h' => 'hours', 'min' => 'minutes', 's' => 'seconds', 'ms' => 'milliseconds',
        'us' => 'microseconds', 'ns' => 'nanoseconds',
        'By' => 'bytes', 'KiBy' => 'kibibytes', 'MiBy' => 'mebibytes', 'GiBy' => 'gibibytes', 'TiBy' => 'tibibytes',
        'KBy' => 'kilobytes', 'MBy' => 'megabytes', 'GBy' => 'gigabytes', 'TBy' => 'terabytes',
        'm' => 'meters', 'V' => 'volts', 'A' => 'amperes', 'J' => 'joules', 'W' => 'watts', 'g' => 'grams',
        'Cel' => 'celsius', 'Hz' => 'hertz', '%' => 'percent',
    ];

    /** The unit after "/" in a rate, such as "By/s". */
    private const PER_UNITS = ['s' => 'second', 'm' => 'minute', 'h' => 'hour', 'd' => 'day', 'w' => 'week', 'mo' => 'month', 'y' => 'year'];

    /**
     * @param  iterable<Metric>  $metrics
     * @param  array<string, string>  $labels  added to every sample; an attribute of the same name wins
     */
    public function render(iterable $metrics, array $labels = []): string
    {
        $constant = [];
        foreach ($labels as $name => $value) {
            $constant[self::labelName((string) $name)] = $value;
        }

        /** @var array<string, array{type: string, help: string, samples: list<string>}> $families */
        $families = [];
        $seen = [];

        foreach ($metrics as $metric) {
            $data = $metric->data;
            $type = match (true) {
                $data instanceof Sum && $data->temporality === Temporality::CUMULATIVE => $data->monotonic ? 'counter' : 'gauge',
                $data instanceof Histogram && $data->temporality === Temporality::CUMULATIVE => 'histogram',
                $data instanceof Gauge => 'gauge',
                default => null,
            };
            if ($type === null) {
                continue;
            }

            $name = self::metricName($metric->name, (string) $metric->unit, $type);
            $family = $families[$name] ??= ['type' => $type, 'help' => '', 'samples' => []];
            if ($family['type'] !== $type) {
                continue;
            }
            if ($family['help'] === '' && (string) $metric->description !== '') {
                $family['help'] = (string) $metric->description;
            }

            $scope = ['otel_scope_name' => $metric->instrumentationScope->getName()];
            $version = $metric->instrumentationScope->getVersion();
            if ($version !== null && $version !== '') {
                $scope['otel_scope_version'] = $version;
            }

            foreach ($data->dataPoints as $point) {
                $pointLabels = [...$constant, ...self::labels($point->attributes->toArray()), ...$scope];
                ksort($pointLabels);
                if ($point instanceof HistogramDataPoint) {
                    $cumulative = 0;
                    foreach ($point->explicitBounds as $i => $bound) {
                        $cumulative += $point->bucketCounts[$i] ?? 0;
                        self::sample($family['samples'], $seen, "{$name}_bucket", [...$pointLabels, 'le' => self::number((float) $bound)], $cumulative);
                    }
                    self::sample($family['samples'], $seen, "{$name}_bucket", [...$pointLabels, 'le' => '+Inf'], $point->count);
                    self::sample($family['samples'], $seen, "{$name}_sum", $pointLabels, $point->sum);
                    self::sample($family['samples'], $seen, "{$name}_count", $pointLabels, $point->count);
                } elseif ($point instanceof NumberDataPoint) {
                    self::sample($family['samples'], $seen, $name, $pointLabels, $point->value);
                }
            }

            $families[$name] = $family;
        }

        ksort($families);
        $output = '';
        foreach ($families as $name => $family) {
            if ($family['samples'] === []) {
                continue;
            }
            if ($family['help'] !== '') {
                $output .= "# HELP {$name} ".strtr($family['help'], ['\\' => '\\\\', "\n" => '\\n'])."\n";
            }
            $output .= "# TYPE {$name} {$family['type']}\n".implode('', $family['samples']);
        }

        return $output;
    }

    private static function metricName(string $name, string $unit, string $type): string
    {
        $name = self::sanitize($name, '/[^a-zA-Z0-9_:]+/');
        $suffix = self::unitWord($unit, $type);

        // "requests_total" and "requests" are the same counter: "_total" goes last, once.
        if ($type === 'counter' && str_ends_with($name, '_total')) {
            $name = substr($name, 0, -6);
        }
        if ($suffix !== '' && ! str_ends_with($name, "_{$suffix}")) {
            $name .= "_{$suffix}";
        }

        return $type === 'counter' ? "{$name}_total" : $name;
    }

    private static function unitWord(string $unit, string $type): string
    {
        // Annotations such as {request} are not units.
        $unit = trim((string) preg_replace('/\{[^}]*\}/', '', $unit));
        if ($unit === '') {
            return '';
        }
        if ($unit === '1') {
            return $type === 'gauge' ? 'ratio' : '';
        }

        [$main, $per] = explode('/', $unit, 2) + [1 => null];
        $words = [];
        if ($main !== '' && $main !== '1') {
            $words[] = self::UNITS[$main] ?? $main;
        }
        if ($per !== null && $per !== '') {
            $words[] = 'per_'.(self::PER_UNITS[$per] ?? $per);
        }

        return self::sanitize(implode('_', $words), '/[^a-zA-Z0-9_]+/');
    }

    /**
     * Attributes as label name => value. Two attributes whose names become the same label have
     * their values joined with ";", in the order of the attribute names.
     *
     * @param  array<array-key, mixed>  $attributes
     * @return array<string, string>
     */
    private static function labels(array $attributes): array
    {
        ksort($attributes);
        $labels = [];
        foreach ($attributes as $key => $value) {
            $value = match (true) {
                is_string($value) => $value,
                is_bool($value) => $value ? 'true' : 'false',
                is_int($value), is_float($value) => self::number((float) $value),
                default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            };
            $label = self::labelName((string) $key);
            $labels[$label] = isset($labels[$label]) ? "{$labels[$label]};{$value}" : $value;
        }

        return $labels;
    }

    private static function labelName(string $name): string
    {
        return self::sanitize($name, '/[^a-zA-Z0-9_]+/');
    }

    private static function sanitize(string $name, string $invalid): string
    {
        $name = (string) preg_replace($invalid, '_', $name);
        $name = (string) preg_replace('/__+/', '_', $name);

        return preg_match('/^[0-9]/', $name) === 1 ? "_{$name}" : $name;
    }

    /**
     * Adds one sample line, unless the same sample is already written.
     *
     * @param  list<string>  $samples
     * @param  array<string, true>  $seen
     * @param  array<string, string>  $labels
     */
    private static function sample(array &$samples, array &$seen, string $name, array $labels, float|int $value): void
    {
        $pairs = [];
        foreach ($labels as $label => $labelValue) {
            $pairs[] = $label.'="'.strtr($labelValue, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n']).'"';
        }
        $sample = $name.($pairs === [] ? '' : '{'.implode(',', $pairs).'}');
        if (isset($seen[$sample])) {
            return;
        }
        $seen[$sample] = true;

        $samples[] = $sample.' '.(is_int($value) ? (string) $value : self::number($value))."\n";
    }

    private static function number(float $value): string
    {
        return match (true) {
            is_nan($value) => 'NaN',
            is_infinite($value) => $value > 0 ? '+Inf' : '-Inf',
            // A whole number is written without a fraction, so that a bucket bound is the same
            // label value in every scrape. Other values keep every digit: a cast to string keeps 14.
            floor($value) === $value && abs($value) < 1e15 => sprintf('%d', $value),
            default => (string) json_encode($value),
        };
    }
}
