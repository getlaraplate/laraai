<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation;

use Modules\Core\Search\DTOs\AdvancedSearchResult;

/**
 * How many evaluated cases the reranker really ran for.
 *
 * An evaluation always asks for the reranked ordering, but `EnsembleSearchService` keeps the fused order and
 * sets `meta['reranked'] = false` when the reranker fails or is disabled. Reading `ids()` alone reports that
 * fused order as the reranker's result, so a service that is down looks like a reranker that adds nothing.
 * This is the one place that reads the flag and words the consequence, shared by both evaluation reports.
 */
final class RerankerRun
{
    /**
     * @return array{requested: int, ran: int, status: 'ran'|'partial'|'not_run', model: string|null}
     */
    public static function summary(int $requested, int $ran, ?string $model = null): array
    {
        return [
            'requested' => $requested,
            'ran' => $ran,
            'status' => match (true) {
                $ran === 0 => 'not_run',
                $ran < $requested => 'partial',
                default => 'ran',
            },
            'model' => $model,
        ];
    }

    /**
     * The model the reranker named for a result, null when it named none.
     */
    public static function modelIn(AdvancedSearchResult $result): ?string
    {
        $model = $result->meta['reranker_model'] ?? null;

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * The model that scored a set of cases: the one model, every model joined when they differ (a report
     * mixing models measures none of them), null when none was named.
     *
     * @param  list<string|null>  $models
     */
    public static function modelOf(array $models): ?string
    {
        $named = array_values(array_unique(array_filter($models, static fn (?string $model): bool => $model !== null)));
        sort($named);

        return $named === [] ? null : implode(', ', $named);
    }

    /**
     * Only an explicit `true` counts: a result without the flag was produced by a path that never reranks.
     */
    public static function ranIn(AdvancedSearchResult $result): bool
    {
        return ($result->meta['reranked'] ?? false) === true;
    }

    /**
     * The warning for a report whose reranker did not run for every case, null when nothing is wrong or the
     * report carries no reranker block.
     *
     * @param  array<string, mixed>  $report
     */
    public static function warning(array $report): ?string
    {
        $run = $report['reranker'] ?? null;

        if (! is_array($run) || ($run['status'] ?? 'ran') === 'ran') {
            return null;
        }

        $counts = sprintf('it ran for %d of %d cases', self::caseCount($run['ran'] ?? null), self::caseCount($run['requested'] ?? null));

        return $run['status'] === 'partial'
            ? "Reranker ran only partly: {$counts}. For the others the reranked figures are the fused order, so they understate the reranker."
            : "Reranker did not run: {$counts}. The reranked figures are the fused order, not a measure of the reranker; check that the cross-encoder service is up.";
    }

    private static function caseCount(mixed $value): int
    {
        return is_int($value) ? $value : 0;
    }
}
