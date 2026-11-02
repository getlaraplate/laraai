<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent\Evaluation;

use Modules\Core\Search\Contracts\IReportsVectorSimilarity;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\DTOs\VectorProfile;

/**
 * The similarity floor `test:ai:tune-retrieval` recommends for the active embedding profile, measured on
 * the vector hits it already records: the similarity of the expected documents among them, and of the
 * others.
 *
 * The recommendation is a low percentile of the similarity of the expected documents
 * ({@see self::RELEVANT_PERCENTILE}, the lowest one below twenty documents), rounded down to two decimals,
 * so nearly every expected document a vector search finds stays above it. It is on the scale of the
 * profile's similarity function, which is the scale of the profile's `min_similarity`: the engine turns
 * each score back into a similarity ({@see IReportsVectorSimilarity}). It is printed, never written: a
 * human sets `min_similarity` on the profile.
 */
final readonly class SimilarityFloorRecommendation
{
    /**
     * The share of the expected documents found by vector search that may fall below the floor.
     */
    public const float RELEVANT_PERCENTILE = 0.05;

    private function __construct(
        public ?VectorProfile $profile,
        public ?float $floor,
        public ?string $unavailableReason,
        public int $relevantHits = 0,
        public int $otherHits = 0,
        public ?float $lowestRelevant = null,
        public ?float $highestOther = null,
        public float $keptRelevantShare = 0.0,
        public float $cutOtherShare = 0.0,
    ) {}

    /**
     * The scores of the vector hits of one case, split into the expected documents and the others. A hit
     * whose score the engine did not give (`score_details.defaulted`) says nothing about similarity and is
     * left out.
     *
     * @param  list<string>  $expectedHitIds  namespaced as `source:id`
     * @return array{relevant: list<float>, other: list<float>}
     */
    public static function vectorScores(AdvancedSearchResult $result, array $expectedHitIds, string $source): array
    {
        $scores = ['relevant' => [], 'other' => []];
        $per_strategy = $result->meta['per_strategy'] ?? [];
        $hits = is_array($per_strategy) && is_array($per_strategy['vector'] ?? null) ? $per_strategy['vector'] : [];

        foreach ($hits as $hit) {
            if (! is_array($hit) || ! is_scalar($hit['id'] ?? null)) {
                continue;
            }

            if (is_array($hit['score_details'] ?? null) && ($hit['score_details']['defaulted'] ?? false) === true) {
                continue;
            }

            $score = $hit['raw_score'] ?? $hit['score'] ?? null;

            if (! is_numeric($score)) {
                continue;
            }

            $group = in_array("{$source}:{$hit['id']}", $expectedHitIds, true) ? 'relevant' : 'other';
            $scores[$group][] = (float) $score;
        }

        return $scores;
    }

    /**
     * @param  list<float>  $relevantScores  engine scores of the expected documents among the vector hits
     * @param  list<float>  $otherScores  engine scores of the other vector hits
     */
    public static function measure(array $relevantScores, array $otherScores, ?IReportsVectorSimilarity $engine, ?VectorProfile $profile): self
    {
        if (! $profile instanceof VectorProfile) {
            return new self(null, null, 'no vector profile is active, so no vector search ran');
        }

        if (! $engine instanceof IReportsVectorSimilarity) {
            return new self($profile, null, 'the search engine of the source does not tell the similarity behind its vector scores');
        }

        if ($relevantScores === [] && $otherScores === []) {
            return new self($profile, null, 'no vector hits were recorded');
        }

        $relevant = self::similarities($relevantScores, $engine, $profile);
        $other = self::similarities($otherScores, $engine, $profile);

        if ($relevant === null || $other === null) {
            return new self($profile, null, "the search engine cannot turn its vector scores back into a '{$profile->similarity}' similarity");
        }

        if ($relevant === []) {
            return new self($profile, null, 'none of the expected documents was among the vector hits');
        }

        sort($relevant);
        $index = max(0, (int) ceil(self::RELEVANT_PERCENTILE * count($relevant)) - 1);
        // The epsilon keeps a value such as 0.29 (28.999... once scaled) from rounding down a whole step.
        $floor = min(1.0, max(0.0, floor($relevant[$index] * 100 + 1e-9) / 100));

        return new self(
            profile: $profile,
            floor: $floor,
            unavailableReason: null,
            relevantHits: count($relevant),
            otherHits: count($other),
            lowestRelevant: $relevant[0],
            highestOther: $other === [] ? null : max($other),
            keptRelevantShare: (float) count(array_filter($relevant, static fn (float $similarity): bool => $similarity >= $floor)) / count($relevant),
            cutOtherShare: $other === [] ? 0.0 : (float) count(array_filter($other, static fn (float $similarity): bool => $similarity < $floor)) / count($other),
        );
    }

    /**
     * The line the tuning command prints.
     */
    public function summary(): string
    {
        if ($this->floor === null) {
            return '// Similarity floor: no recommendation, ' . ($this->unavailableReason ?? 'nothing was measured') . '.';
        }

        $key = $this->profile->modelKey ?? '?';
        $line = sprintf(
            "// Similarity floor (printed only, never written: set 'min_similarity' on the profile '%s' by hand): %s, on the '%s' similarity scale. It keeps %d%% of the %d expected documents among the vector hits (lowest %s)",
            $key,
            number_format($this->floor, 2, '.', ''),
            $this->profile->similarity ?? '?',
            (int) round($this->keptRelevantShare * 100),
            $this->relevantHits,
            number_format((float) $this->lowestRelevant, 3, '.', ''),
        );

        $line .= $this->highestOther === null
            ? ' and there were no other vector hits to cut.'
            : sprintf(
                ' and cuts %d%% of the %d other vector hits (highest %s).',
                (int) round($this->cutOtherShare * 100),
                $this->otherHits,
                number_format($this->highestOther, 3, '.', ''),
            );

        $current = $this->profile?->similarityFloor();

        if ($current !== null) {
            $line .= sprintf(' Measured with the floor %s in force: hits below it were not seen.', number_format($current, 2, '.', ''));
        }

        return $line;
    }

    /**
     * The similarities behind the scores, or null when the engine cannot tell one of them.
     *
     * @param  list<float>  $scores
     * @return list<float>|null
     */
    private static function similarities(array $scores, IReportsVectorSimilarity $engine, VectorProfile $profile): ?array
    {
        $similarities = [];

        foreach ($scores as $score) {
            $similarity = $engine->vectorSimilarity($score, $profile);

            if ($similarity === null) {
                return null;
            }

            $similarities[] = $similarity;
        }

        return $similarities;
    }
}
