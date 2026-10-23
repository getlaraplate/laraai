<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingDimensionProbe;
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
