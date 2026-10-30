<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Embeddings\ActiveEmbeddingProfileProvider;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\Core\Search\Contracts\IVectorProfileProvider;
use Modules\Core\Search\DTOs\VectorProfile;

beforeEach(function (): void {
    config()->set('ai.features.embeddings.enabled', true);
    config()->set('ai.features.embeddings.serving_model', 'openai_compatible:intfloat/multilingual-e5-small');
});

function activeProfileProvider(): ActiveEmbeddingProfileProvider
{
    return new ActiveEmbeddingProfileProvider(new EmbeddingModelRegistry);
}

it('answers null while features.embeddings.enabled is off', function (): void {
    config()->set('ai.features.embeddings.enabled', false);

    expect(activeProfileProvider()->current())->toBeNull();
});

it('answers null and logs one warning when the active key is not in config', function (): void {
    config()->set('ai.features.embeddings.serving_model', 'nope:missing');
    Log::spy();

    $provider = activeProfileProvider();

    expect($provider->current())->toBeNull()
        ->and($provider->current())->toBeNull();

    Log::shouldHaveReceived('warning')->once();
});

it('answers the key, dimensions and similarity of the active profile when on', function (): void {
    config()->set('ai.features.embeddings.models.openai_compatible:intfloat/multilingual-e5-small.similarity', 'dot_product');

    $profile = activeProfileProvider()->current();

    expect($profile)->toEqual(new VectorProfile('openai_compatible:intfloat/multilingual-e5-small', 384, 'dot_product'));
});

it('follows the serving model setting', function (): void {
    config()->set('ai.features.embeddings.serving_model', 'openai_compatible:all-MiniLM-L6-v2');

    expect(activeProfileProvider()->current()?->modelKey)->toBe('openai_compatible:all-MiniLM-L6-v2');
});

it('runs no database query', function (): void {
    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    activeProfileProvider()->current();

    expect($queries)->toBe([]);
});

it('is the binding Core resolves for the vector profile', function (): void {
    expect(app(IVectorProfileProvider::class))->toBeInstanceOf(ActiveEmbeddingProfileProvider::class);
});
