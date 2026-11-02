<?php

declare(strict_types=1);

use Modules\AI\Services\ApplicationContent\Evaluation\SimilarityFloorRecommendation;
use Modules\AI\Tests\Stubs\ApplicationContent\ScoreIsSimilarityEngine;
use Modules\Core\Search\DTOs\AdvancedSearchResult;
use Modules\Core\Search\DTOs\VectorProfile;

function floor_profile(string $similarity = 'cosine', ?float $minSimilarity = null): VectorProfile
{
    return new VectorProfile('test:model', 8, $similarity, $minSimilarity);
}

it('splits the vector hits of a case into expected and other documents', function (): void {
    $result = new AdvancedSearchResult(hits: [], total: 0, page: 1, perPage: 5, totalPages: 0, meta: ['per_strategy' => [
        'keyword' => ['9' => ['id' => '9', 'score' => 12.0, 'raw_score' => 12.0, 'score_details' => [], 'source' => [], 'rank' => 1]],
        'vector' => [
            '2' => ['id' => '2', 'score' => 0.91, 'raw_score' => 0.91, 'score_details' => [], 'source' => [], 'rank' => 1],
            '1' => ['id' => '1', 'score' => 0.74, 'raw_score' => 0.74, 'score_details' => [], 'source' => [], 'rank' => 2],
            '3' => ['id' => '3', 'score' => 1.0, 'raw_score' => 1.0, 'score_details' => ['defaulted' => true], 'source' => [], 'rank' => 3],
        ],
    ]]);

    expect(SimilarityFloorRecommendation::vectorScores($result, ['cms.records:2', 'cms.records:3'], 'cms.records'))
        ->toBe(['relevant' => [0.91], 'other' => [0.74]]);
});

it('recommends the lowest similarity of the expected documents, rounded down, below twenty of them', function (): void {
    $recommendation = SimilarityFloorRecommendation::measure([0.83, 0.7178, 0.9, 0.77], [0.5, 0.65, 0.74, 0.8], new ScoreIsSimilarityEngine, floor_profile());

    expect($recommendation->floor)->toBe(0.71)
        ->and($recommendation->unavailableReason)->toBeNull()
        ->and($recommendation->keptRelevantShare)->toBe(1.0)
        ->and($recommendation->cutOtherShare)->toBe(0.5)
        ->and($recommendation->highestOther)->toBe(0.8)
        ->and($recommendation->summary())->toContain('0.71')
        ->and($recommendation->summary())->toContain('test:model')
        ->and($recommendation->summary())->toContain('never written');
});

it('leaves out the lowest outlier once there are enough expected documents', function (): void {
    $relevant = [0.3, ...array_fill(0, 39, 0.8)];

    $recommendation = SimilarityFloorRecommendation::measure($relevant, [0.5], new ScoreIsSimilarityEngine, floor_profile());

    expect($recommendation->floor)->toBe(0.8)
        ->and($recommendation->keptRelevantShare)->toBe(39 / 40);
});

it('says the hits below the floor in force were not seen', function (): void {
    $recommendation = SimilarityFloorRecommendation::measure([0.8], [0.7], new ScoreIsSimilarityEngine, floor_profile(minSimilarity: 0.65));

    expect($recommendation->summary())->toContain('0.65');
});

it('gives no recommendation, with the reason, when it cannot measure one', function (array $relevant, array $other, bool $withEngine, ?VectorProfile $profile, string $reason): void {
    $recommendation = SimilarityFloorRecommendation::measure($relevant, $other, $withEngine ? new ScoreIsSimilarityEngine : null, $profile);

    expect($recommendation->floor)->toBeNull()
        ->and($recommendation->unavailableReason)->toContain($reason)
        ->and($recommendation->summary())->toContain($reason);
})->with([
    'no vector profile' => [[0.8], [], true, null, 'no vector profile'],
    'an engine that does not tell' => [[0.8], [], false, floor_profile(), 'does not tell'],
    'a similarity the engine cannot turn back' => [[0.8], [], true, floor_profile('l2_norm'), "'l2_norm'"],
    'no vector hit' => [[], [], true, floor_profile(), 'no vector hits'],
    'no expected document among the vector hits' => [[], [0.6], true, floor_profile(), 'none of the expected documents'],
]);
