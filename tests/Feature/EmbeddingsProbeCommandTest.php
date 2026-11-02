<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionProbe;
use Modules\AI\Ai\Embeddings\EmbeddingDriftReference;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

/**
 * Puts a probe in the container whose provider answers with a vector of $measured components.
 */
function probe_command_with_length(int $measured): void
{
    $provider = Mockery::mock(EmbeddingsProviderInterface::class);
    $provider->shouldReceive('embedText')->andReturn(array_fill(0, $measured, 0.1));

    app()->instance(EmbeddingDimensionProbe::class, new EmbeddingDimensionProbe(
        app(EmbeddingModelRegistry::class),
        static fn (): EmbeddingsProviderInterface => $provider,
    ));
}

it('prints the profile, its model and the measured dimensions', function (): void {
    probe_command_with_length(384);

    $this->artisan('ai:embeddings:probe', ['profile' => 'openai_compatible:all-MiniLM-L6-v2'])
        ->expectsOutputToContain('openai_compatible:all-MiniLM-L6-v2')
        ->expectsOutputToContain('all-MiniLM-L6-v2')
        ->expectsOutputToContain('384')
        ->assertSuccessful();
});

it('fails with the mismatch message when declared and measured differ', function (): void {
    probe_command_with_length(768);

    $this->artisan('ai:embeddings:probe', ['profile' => 'openai_compatible:all-MiniLM-L6-v2'])
        ->expectsOutputToContain('declares 384')
        ->assertFailed();
});

it('fails for an unknown profile', function (): void {
    $this->artisan('ai:embeddings:probe', ['profile' => 'nope:nothing'])
        ->expectsOutputToContain('Unknown embedding model profile')
        ->assertFailed();
});

/**
 * Puts a probe in the container whose provider answers each sample text with the vector the
 * callback builds from the text's position in the sample.
 *
 * @param  Closure(int): list<float>  $vectorFor
 */
function probe_command_with_sample_vectors(Closure $vectorFor): void
{
    $provider = Mockery::mock(EmbeddingsProviderInterface::class);
    $provider->shouldReceive('embedText')->andReturnUsing(static function (string $text) use ($vectorFor): array {
        $index = array_search($text, EmbeddingDriftReference::SAMPLE, true);

        return $vectorFor($index === false ? 0 : $index);
    });

    app()->instance(EmbeddingDimensionProbe::class, new EmbeddingDimensionProbe(
        app(EmbeddingModelRegistry::class),
        static fn (): EmbeddingsProviderInterface => $provider,
    ));
}

it('does not touch the reference without the option', function (): void {
    Storage::fake('local');
    probe_command_with_length(384);

    $this->artisan('ai:embeddings:probe', ['profile' => 'openai_compatible:all-MiniLM-L6-v2'])->assertSuccessful();

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

it('saves a reference with --reference=save', function (): void {
    Storage::fake('local');
    probe_command_with_sample_vectors(static fn (int $index): array => array_replace(array_fill(0, 384, 0.1), [0 => 1.0 + $index]));

    $this->artisan('ai:embeddings:probe', ['profile' => 'openai_compatible:all-MiniLM-L6-v2', '--reference' => 'save'])
        ->expectsOutputToContain('reference saved')
        ->assertSuccessful();

    expect(Storage::disk('local')->allFiles('ai/embedding-references'))->toHaveCount(1);
});

it('compares with the saved reference and succeeds when nothing drifted', function (): void {
    Storage::fake('local');
    $vectorFor = static fn (int $index): array => array_replace(array_fill(0, 384, 0.1), [0 => 1.0 + $index]);
    probe_command_with_sample_vectors($vectorFor);

    $this->artisan('ai:embeddings:probe', ['profile' => 'openai_compatible:all-MiniLM-L6-v2', '--reference' => 'save'])->assertSuccessful();

    $this->artisan('ai:embeddings:probe', ['profile' => 'openai_compatible:all-MiniLM-L6-v2', '--reference' => 'compare'])
        ->expectsOutputToContain('lowest cosine: 1.000000')
        ->assertSuccessful();
});

it('compare fails below the floor and names the text with the lowest cosine', function (): void {
    Storage::fake('local');
    probe_command_with_sample_vectors(static fn (int $index): array => array_replace(array_fill(0, 384, 0.1), [0 => 1.0 + $index]));
    $this->artisan('ai:embeddings:probe', ['profile' => 'openai_compatible:all-MiniLM-L6-v2', '--reference' => 'save'])->assertSuccessful();

    probe_command_with_sample_vectors(static fn (int $index): array => $index === 2
        ? array_replace(array_fill(0, 384, 0.0), [5 => 1.0])
        : array_replace(array_fill(0, 384, 0.1), [0 => 1.0 + $index]));

    $this->artisan('ai:embeddings:probe', ['profile' => 'openai_compatible:all-MiniLM-L6-v2', '--reference' => 'compare'])
        ->expectsOutputToContain(EmbeddingDriftReference::SAMPLE[2])
        ->expectsOutputToContain('below the floor')
        ->assertFailed();
});

it('compare honours the configured floor', function (): void {
    Storage::fake('local');
    probe_command_with_sample_vectors(static fn (int $index): array => array_replace(array_fill(0, 384, 0.1), [0 => 1.0 + $index]));
    $this->artisan('ai:embeddings:probe', ['profile' => 'openai_compatible:all-MiniLM-L6-v2', '--reference' => 'save'])->assertSuccessful();

    probe_command_with_sample_vectors(static fn (int $index): array => array_replace(array_fill(0, 384, 0.1), [0 => 1.2 + $index]));

    config()->set('ai.features.embeddings.drift_floor', 0.5);

    $this->artisan('ai:embeddings:probe', ['profile' => 'openai_compatible:all-MiniLM-L6-v2', '--reference' => 'compare'])->assertSuccessful();
});

it('compare without a saved reference fails with a message, not a trace', function (): void {
    Storage::fake('local');
    probe_command_with_sample_vectors(static fn (int $index): array => array_fill(0, 384, 0.1));

    $this->artisan('ai:embeddings:probe', ['profile' => 'openai_compatible:all-MiniLM-L6-v2', '--reference' => 'compare'])
        ->expectsOutputToContain('No embedding reference saved')
        ->assertFailed();
});

it('rejects an unknown --reference value', function (): void {
    probe_command_with_length(384);

    $this->artisan('ai:embeddings:probe', ['profile' => 'openai_compatible:all-MiniLM-L6-v2', '--reference' => 'nope'])
        ->expectsOutputToContain('--reference')
        ->assertFailed();
});
