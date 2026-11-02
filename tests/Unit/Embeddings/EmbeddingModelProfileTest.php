<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;

it('tells a service model by its last path segment, ignoring case and spaces', function (string $reported, string $expected, bool $same): void {
    expect(EmbeddingModelProfile::sameServiceModel($reported, $expected))->toBe($same);
})->with([
    'identical' => ['intfloat/multilingual-e5-small', 'intfloat/multilingual-e5-small', true],
    'another organisation prefix' => ['sentence-transformers/all-MiniLM-L6-v2', 'all-MiniLM-L6-v2', true],
    'case' => ['Intfloat/Multilingual-E5-Small', 'intfloat/multilingual-e5-small', true],
    'spaces' => ['  all-MiniLM-L6-v2 ', 'all-MiniLM-L6-v2', true],
    'another model' => ['sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2', 'intfloat/multilingual-e5-small', false],
    'a name that only ends the same' => ['xmultilingual-e5-small', 'multilingual-e5-small', false],
    'an empty name' => ['', 'all-MiniLM-L6-v2', false],
]);

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function floor_test_model(array $extra = []): array
{
    return ['dimensions' => 8, 'similarity' => 'cosine', ...$extra];
}

it('the profile passes its floor through vectorProfile()', function (): void {
    $profile = new EmbeddingModelProfile('a:b', 'a', 'b', 8, '', '', 'cosine', false, minSimilarity: 0.62);

    expect($profile->vectorProfile()->minSimilarity)->toBe(0.62)
        ->and($profile->vectorProfile()->similarityFloor())->toBe(0.62);
});

it('keeps no floor when the profile declares none', function (): void {
    $profile = new EmbeddingModelProfile('a:b', 'a', 'b', 8, '', '', 'cosine', false);

    expect($profile->minSimilarity)->toBeNull()
        ->and($profile->vectorProfile()->minSimilarity)->toBeNull();
});

it('switching profile switches the floor', function (): void {
    config()->set('ai.features.embeddings.models', [
        'test:first-model' => floor_test_model(['min_similarity' => 0.55]),
        'test:second-model' => floor_test_model(['min_similarity' => 0.8]),
        'test:third-model' => floor_test_model(),
    ]);
    config()->set('ai.features.embeddings.serving_model', 'test:first-model');

    $registry = app(EmbeddingModelRegistry::class);

    expect($registry->active()->vectorProfile()->minSimilarity)->toBe(0.55)
        ->and($registry->withActive('test:second-model', fn (): ?float => $registry->active()->vectorProfile()->minSimilarity))->toBe(0.8)
        ->and($registry->withActive('test:third-model', fn (): ?float => $registry->active()->vectorProfile()->minSimilarity))->toBeNull();
});

it('reads a floor between 0 and 1 and ignores anything else', function (mixed $configured, ?float $expected): void {
    config()->set('ai.features.embeddings.models', ['test:floor-model' => floor_test_model(['min_similarity' => $configured])]);

    expect(app(EmbeddingModelRegistry::class)->get('test:floor-model')->minSimilarity)->toBe($expected);
})->with([
    'a fraction' => [0.7, 0.7],
    'zero' => [0, 0.0],
    'one' => [1, 1.0],
    'a numeric string' => ['0.6', 0.6],
    'above one' => [1.5, null],
    'negative' => [-0.1, null],
    'not a number' => ['high', null],
    'a boolean' => [true, null],
    'empty' => [null, null],
]);
