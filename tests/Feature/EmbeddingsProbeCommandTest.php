<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
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

beforeEach(function (): void {
    config()->set('ai.servers.embeddings.url', 'http://tei.test:8080');
    Http::preventStrayRequests();
    probe_server_answers('all-MiniLM-L6-v2', ai_test_unit_vector(384));
});

/**
 * The server answers the identity probe with `$model` and `$vector`, and has no /info.
 *
 * @param  list<float>  $vector
 */
function probe_server_answers(?string $model, array $vector): void
{
    Http::swap(new Illuminate\Http\Client\Factory());
    Http::preventStrayRequests();
    Http::fake([
        '*/v1/embeddings' => Http::response([...($model === null ? [] : ['model' => $model]), 'data' => [['index' => 0, 'embedding' => $vector]]]),
        '*/info' => Http::response('not found', 404),
    ]);
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

it('fails when the server runs another model than the profile', function (): void {
    probe_command_with_length(384);
    probe_server_answers('BAAI/bge-m3', ai_test_unit_vector(384));

    $this->artisan('ai:embeddings:probe', ['profile' => 'openai_compatible:all-MiniLM-L6-v2'])
        ->expectsOutputToContain('reports model "BAAI/bge-m3"')
        ->assertFailed();
});

it('fails when the server returns vectors that are not normalised for a normalising profile', function (): void {
    probe_command_with_length(384);
    probe_server_answers('all-MiniLM-L6-v2', array_fill(0, 384, 0.5));

    $this->artisan('ai:embeddings:probe', ['profile' => 'openai_compatible:all-MiniLM-L6-v2'])
        ->expectsOutputToContain('not normalised')
        ->assertFailed();
});
