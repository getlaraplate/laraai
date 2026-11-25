<?php

declare(strict_types=1);

namespace Modules\AI\Services\Evaluation;

use Closure;

/**
 * The arithmetic that every evaluation report of the module shares: ratios and percentiles rounded to
 * four decimals, the latency summary of a run, and the regrouping of its records by the locale and
 * the slices of their cases. Pure functions: a report keeps only the metrics of its own domain.
 */
final class EvaluationStatistics
{
    public static function rounded(float $value): float
    {
        return round($value, 4);
    }

    public static function ratio(float|int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : self::rounded($numerator / $denominator);
    }

    /**
     * @param  list<float>  $values  sorted ascending
     */
    public static function percentile(array $values, float $percentile): float
    {
        $index = max(0, (int) ceil($percentile * count($values)) - 1);

        return $values[$index];
    }

    /**
     * Average, median, 95th percentile and maximum of the `latency_ms` of the records, in milliseconds.
     *
     * @param  list<array{latency_ms: float, ...<string, mixed>}>  $records
     * @return array<string, float>
     */
    public static function latency(array $records): array
    {
        if ($records === []) {
            return ['average' => 0.0, 'p50' => 0.0, 'p95' => 0.0, 'max' => 0.0];
        }

        $values = array_map(static fn (array $record): float => $record['latency_ms'], $records);
        sort($values, SORT_NUMERIC);

        return [
            'average' => self::rounded(array_sum($values) / count($values)),
            'p50' => self::rounded(self::percentile($values, 0.50)),
            'p95' => self::rounded(self::percentile($values, 0.95)),
            'max' => self::rounded(max($values)),
        ];
    }

    /**
     * The metrics of the records of each locale and of each slice of their cases, keys in order. A
     * record carries its case under `case`, an object with a `locale` and a list of `slices`.
     *
     * @template TRecord of array{case: object{locale: string, slices: list<string>}, ...<string, mixed>}
     * @template TMetrics
     *
     * @param  list<TRecord>  $records
     * @param  Closure(list<TRecord>): TMetrics  $metrics
     * @return array{locale: array<string, TMetrics>, category: array<string, TMetrics>}
     */
    public static function groupBySlices(array $records, Closure $metrics): array
    {
        $locales = [];
        $categories = [];

        foreach ($records as $record) {
            $case = $record['case'];
            $locales[$case->locale][] = $record;

            foreach ($case->slices as $slice) {
                $categories[$slice][] = $record;
            }
        }

        ksort($locales, SORT_STRING);
        ksort($categories, SORT_STRING);

        return [
            'locale' => array_map($metrics, $locales),
            'category' => array_map($metrics, $categories),
        ];
    }
}
