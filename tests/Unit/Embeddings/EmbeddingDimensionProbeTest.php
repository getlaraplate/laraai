<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingDimensionMismatch;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionProbe;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\EmbeddingsProviderFactory;
use Modules\AI\Ai\Embeddings\PrefixingEmbeddingsProvider;
use Modules\AI\Tests\Stubs\ScriptedHttpClient;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\HttpClient\HttpResponse;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

/**
 * A probe whose provider, built by the real factory for the profile under test, talks to a fake
 * server answering with the given vector; the JSON body of the request lands in $captured.
 *
 * @param  list<float>  $vector
 * @param  array<string, mixed>|null  $captured
 */
function probe_with_service_answering(array $vector, ?array &$captured = null): EmbeddingDimensionProbe
{
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');

    $client = new ScriptedHttpClient(static function (HttpRequest $request) use ($vector, &$captured): HttpResponse {
        $captured = $request->body;

        return new HttpResponse(200, json_encode(['data' => [['index' => 0, 'embedding' => $vector]]], JSON_THROW_ON_ERROR));
    });

    return new EmbeddingDimensionProbe(
        app(EmbeddingModelRegistry::class),
        static function () use ($client): EmbeddingsProviderInterface {
            $provider = EmbeddingsProviderFactory::make();
            $inner = $provider instanceof PrefixingEmbeddingsProvider ? (fn (): EmbeddingsProviderInterface => $this->inner)->call($provider) : $provider;
            $inner->setHttpClient($client);

            return $provider;
        },
    );
}

it('measures the length of the vector the service returns', function (): void {
    $profile = app(EmbeddingModelRegistry::class)->get('openai_compatible:all-MiniLM-L6-v2');
    $probe = probe_with_service_answering(array_fill(0, 384, 0.1));

    expect($probe->measure($profile))->toBe(384);
});

it('embeds with the service model of the profile it measures, not the active one', function (): void {
    $captured = null;
    $registry = app(EmbeddingModelRegistry::class);
    $profile = $registry->get('openai_compatible:all-MiniLM-L6-v2');

    probe_with_service_answering(array_fill(0, 384, 0.1), $captured)->measure($profile);

    expect($captured['model'])->toBe('all-MiniLM-L6-v2')
        ->and($captured['input'])->toBe(['embedding service probe'])
        ->and($registry->active()->key)->toBe('openai_compatible:intfloat/multilingual-e5-small');
});

it('verify returns the measured length when it equals the declared dimensions', function (): void {
    $profile = app(EmbeddingModelRegistry::class)->get('openai_compatible:all-MiniLM-L6-v2');

    expect(probe_with_service_answering(array_fill(0, 384, 0.1))->verify($profile))->toBe(384);
});

it('verify throws naming profile, declared and measured when they differ', function (): void {
    $profile = app(EmbeddingModelRegistry::class)->get('openai_compatible:all-MiniLM-L6-v2');
    $probe = probe_with_service_answering(array_fill(0, 768, 0.1));

    $caught = null;

    try {
        $probe->verify($profile);
    } catch (EmbeddingDimensionMismatch $mismatch) {
        $caught = $mismatch;
    }

    expect($caught)->toBeInstanceOf(RuntimeException::class)
        ->and($caught->getMessage())->toContain('openai_compatible:all-MiniLM-L6-v2')->toContain('384')->toContain('768')->toContain('dimensions');
});

it('throws on an empty vector', function (): void {
    $profile = app(EmbeddingModelRegistry::class)->get('openai_compatible:all-MiniLM-L6-v2');
    $probe = probe_with_service_answering([]);

    expect(fn (): int => $probe->measure($profile))->toThrow(EmbeddingDimensionMismatch::class);
});

it('checks a vector obtained elsewhere against the declared dimensions', function (mixed $vector, string $message): void {
    $profile = app(EmbeddingModelRegistry::class)->get('openai_compatible:all-MiniLM-L6-v2');

    expect(fn (): int => EmbeddingDimensionProbe::assertDimensions($profile, $vector))
        ->toThrow(EmbeddingDimensionMismatch::class, $message);
})->with([
    'missing' => [null, 'no embedding'],
    'empty' => [[], 'no embedding'],
    'wrong length' => [[0.1, 0.2], 'dimensions'],
]);
