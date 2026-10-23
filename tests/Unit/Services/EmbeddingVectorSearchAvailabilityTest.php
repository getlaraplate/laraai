<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Services\EmbeddingVectorSearchAvailability;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\Contracts\IVectorSearchAvailability;
use Modules\Core\Search\DTOs\VectorAvailability;

uses(RefreshDatabase::class);

const ACTIVE_KEY = 'openai_compatible:intfloat/multilingual-e5-small';

beforeEach(function (): void {
    config()->set('core.search.vector.model', ACTIVE_KEY);
});

function availabilityWith(VectorAvailability $inner): EmbeddingVectorSearchAvailability
{
    $core = Mockery::mock(IVectorSearchAvailability::class);
    $core->shouldReceive('check')->andReturn($inner);

    return new EmbeddingVectorSearchAvailability($core, app(EmbeddingModelRegistry::class));
}

function stampedEmbedding(string $modelKey): void
{
    ModelEmbedding::query()->forceCreate([
        'model_type' => 'x',
        'model_id' => 1,
        'embedding' => array_fill(0, 384, 0.1),
        'locale' => 'en',
        'model_key' => $modelKey,
        'content_hash' => 'h',
    ]);
}

it('returns the Core answer when Core says no', function (): void {
    $answer = availabilityWith(VectorAvailability::no('suspended'))->check(new EmbeddableTestModel);

    expect($answer->available)->toBeFalse()
        ->and($answer->reason)->toBe('suspended');
});

it('answers no_vectors when no embedding is stamped with the active profile', function (): void {
    stampedEmbedding('openai_compatible:all-MiniLM-L6-v2');

    $answer = availabilityWith(VectorAvailability::yes())->check(new EmbeddableTestModel);

    expect($answer->available)->toBeFalse()
        ->and($answer->reason)->toBe('no_vectors');
});

it('answers no_vectors for an empty table', function (): void {
    expect(availabilityWith(VectorAvailability::yes())->check(new EmbeddableTestModel)->reason)->toBe('no_vectors');
});

it('delegates to yes when a vector of the active profile exists', function (): void {
    stampedEmbedding(ACTIVE_KEY);

    expect(availabilityWith(VectorAvailability::yes())->check(new EmbeddableTestModel)->available)->toBeTrue();
});

it('is bound over the Core guard', function (): void {
    expect(app(IVectorSearchAvailability::class))->toBeInstanceOf(EmbeddingVectorSearchAvailability::class);
});

it('answers no_vectors instead of throwing when no active profile resolves', function (?string $active): void {
    config()->set('ai.features.embeddings.models', []);
    config()->set('core.search.vector.model', $active);

    $answer = availabilityWith(VectorAvailability::yes())->check(new EmbeddableTestModel);

    expect($answer->available)->toBeFalse()
        ->and($answer->reason)->toBe('no_vectors');
})->with(['unset' => [null], 'unknown key' => ['nope:missing']]);

it('still lets the Core answer win when no active profile resolves', function (): void {
    config()->set('ai.features.embeddings.models', []);
    config()->set('core.search.vector.model', null);

    expect(availabilityWith(VectorAvailability::no('suspended'))->check(new EmbeddableTestModel)->reason)->toBe('suspended');
});
