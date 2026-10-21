<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingServiceIdentity;
use Modules\AI\Ai\SelfHosted\SelfHostedServiceException;

beforeEach(function (): void {
    config(['ai.providers.sentence_transformers.url' => 'http://st.test:8000']);
});

function identity_profile(): EmbeddingModelProfile
{
    return new EmbeddingModelProfile('e5-small', 'sentence_transformers', 'intfloat/multilingual-e5-small', 3, 'query: ', 'passage: ', 'cosine', true);
}

it('sends a request id of 32 hex characters to /health and to the /embed probe', function (): void {
    Http::fake([
        '*/health' => Http::response(['model' => 'intfloat/multilingual-e5-small']),
        '*/embed' => Http::response(['model' => 'intfloat/multilingual-e5-small', 'embeddings' => [[0.1, 0.2, 0.3]]]),
    ]);

    $identity = new EmbeddingServiceIdentity();
    $identity->healthModel();
    $identity->probeModel(identity_profile());

    $ids = Http::recorded()->map(fn (array $pair): string => $pair[0]->header('X-Request-Id')[0] ?? '')->all();

    expect($ids)->toHaveCount(2)
        ->and($ids[0])->toMatch('/^[0-9a-f]{32}$/')
        ->and($ids[1])->toMatch('/^[0-9a-f]{32}$/');
});

/**
 * The commands print the exception message, so the request id in it is what leads to the service log.
 */
it('fails with the request id when /health answers with an error, and logs it once', function (): void {
    Log::spy();
    Http::fake(['*/health' => Http::response(['error' => 'internal error'], 500)]);

    try {
        (new EmbeddingServiceIdentity())->healthModel();
        $this->fail('The health check should have failed.');
    } catch (SelfHostedServiceException $exception) {
        $id = Http::recorded()->first()[0]->header('X-Request-Id')[0];

        expect($exception->getMessage())->toContain("request_id {$id}");
    }

    Log::shouldHaveReceived('warning')->once()->with(Mockery::type('string'), [
        'service' => 'sentence_transformers',
        'endpoint' => 'http://st.test:8000/health',
        'status' => 500,
        'request_id' => $id,
    ]);
});

it('fails with the request id and the original reason when the probe cannot reach the service', function (): void {
    Log::spy();
    Http::fake(['*/embed' => static fn () => throw new ConnectionException('connection refused')]);

    expect(fn () => (new EmbeddingServiceIdentity())->probeModel(identity_profile()))
        ->toThrow(SelfHostedServiceException::class, 'connection refused');

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context['endpoint'] === 'http://st.test:8000/embed' && $context['status'] === null,
    );
});
