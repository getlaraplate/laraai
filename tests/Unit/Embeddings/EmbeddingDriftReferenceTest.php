<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Modules\AI\Ai\Embeddings\EmbeddingDriftReference;
use Modules\AI\Ai\Embeddings\EmbeddingDriftReferenceException;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;

beforeEach(function (): void {
    Storage::fake('local');
});

/**
 * @param  non-empty-string  $key
 */
function drift_profile(string $key = 'openai_compatible:intfloat/multilingual-e5-small', int $dimensions = 4): EmbeddingModelProfile
{
    return new EmbeddingModelProfile($key, 'openai_compatible', 'model', $dimensions, '', '', 'cosine', true);
}

/**
 * One vector per sample text, each unit-length and different from its neighbours.
 *
 * @return list<list<float>>
 */
function drift_vectors(): array
{
    $vectors = [];

    foreach (EmbeddingDriftReference::SAMPLE as $index => $text) {
        $vectors[] = [1.0 + $index, 2.0, 3.0 - $index, 0.5];
    }

    return $vectors;
}

it('ships a sample of eight short texts in at least four languages', function (): void {
    expect(EmbeddingDriftReference::SAMPLE)->toHaveCount(8)
        ->and(array_unique(EmbeddingDriftReference::SAMPLE))->toHaveCount(8)
        ->and(array_keys(EmbeddingDriftReference::SAMPLE))->toBe(range(0, 7));
});

it('save then compare of identical vectors is 1.0', function (): void {
    $reference = new EmbeddingDriftReference();
    $reference->save(drift_profile(), drift_vectors());

    expect($reference->compare(drift_profile(), drift_vectors()))->toEqualWithDelta(1.0, 1e-9);
});

it('stores the reference as JSON on the local disk', function (): void {
    (new EmbeddingDriftReference())->save(drift_profile(), drift_vectors());

    $files = Storage::disk('local')->allFiles('ai/embedding-references');

    expect($files)->toHaveCount(1)
        ->and($files[0])->toEndWith('.json')
        ->and($files[0])->not->toContain(':')
        ->and(json_decode(Storage::disk('local')->get($files[0]), true, 512, JSON_THROW_ON_ERROR))->toBeArray();
});

it('compare reports the lowest cosine and names the text that has it', function (): void {
    $reference = new EmbeddingDriftReference();
    $reference->save(drift_profile(), drift_vectors());

    $drifted = drift_vectors();
    $drifted[3] = [0.0, 0.0, 1.0, 0.0];

    $lowest = $reference->lowest(drift_profile(), $drifted);

    expect($lowest['text'])->toBe(EmbeddingDriftReference::SAMPLE[3])
        ->and($lowest['cosine'])->toBeLessThan(0.999)
        ->and($reference->compare(drift_profile(), $drifted))->toBe($lowest['cosine']);
});

it('compare without a saved reference is a clear error', function (): void {
    expect(fn (): float => (new EmbeddingDriftReference())->compare(drift_profile(), drift_vectors()))
        ->toThrow(EmbeddingDriftReferenceException::class, 'No embedding reference saved for profile');
});

it('references are per profile key', function (): void {
    $reference = new EmbeddingDriftReference();
    $reference->save(drift_profile('openai_compatible:a/b'), drift_vectors());

    expect(fn (): float => $reference->compare(drift_profile('openai_compatible:a_b'), drift_vectors()))
        ->toThrow(EmbeddingDriftReferenceException::class, 'No embedding reference saved')
        ->and($reference->compare(drift_profile('openai_compatible:a/b'), drift_vectors()))->toEqualWithDelta(1.0, 1e-9);
});

it('fails naming both lengths when a vector differs in length from the reference', function (): void {
    $reference = new EmbeddingDriftReference();
    $reference->save(drift_profile(), drift_vectors());

    $longer = array_map(static fn (array $vector): array => [...$vector, 0.1], drift_vectors());

    expect(fn (): float => $reference->compare(drift_profile(), $longer))
        ->toThrow(EmbeddingDriftReferenceException::class, 'reference has 4 dimensions but the probe returned 5');
});

it('refuses a vector list that does not match the sample', function (): void {
    expect(fn () => (new EmbeddingDriftReference())->save(drift_profile(), [[0.1, 0.2]]))
        ->toThrow(EmbeddingDriftReferenceException::class, 'expects 8 vectors');
});

it('refuses a reference saved for another sample', function (): void {
    Storage::disk('local')->put('ai/embedding-references/' . EmbeddingDriftReference::fileNameFor('p:m'), json_encode([
        'sample' => 'something-else',
        'vectors' => [[1.0]],
    ], JSON_THROW_ON_ERROR));

    expect(fn (): float => (new EmbeddingDriftReference())->compare(drift_profile('p:m'), drift_vectors()))
        ->toThrow(EmbeddingDriftReferenceException::class, 'different sample');
});
