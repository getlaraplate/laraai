<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionMismatch;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingServerIdentity;
use Modules\AI\Ai\SelfHosted\SelfHostedServiceException;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['ai.servers.embeddings' => ['url' => 'http://tei.test:8080', 'api_key' => 'secret-key', 'timeout' => 30, 'batch_size' => 32]]);
    config(['ai.servers.embeddings_other' => ['url' => 'http://other.test:8081', 'api_key' => null]]);
});

function identity_profile(bool $normalize = true, ?string $server = null): EmbeddingModelProfile
{
    return new EmbeddingModelProfile('openai_compatible:intfloat/multilingual-e5-small', 'openai_compatible', 'intfloat/multilingual-e5-small', 3, 'query: ', 'passage: ', 'cosine', $normalize, $server);
}

/**
 * Replaces every fake of the test: Http::fake() registrations are additive and the first match wins, so a
 * test that fakes the same route twice would keep seeing the first answer.
 *
 * @param  array<string, mixed>  $routes
 */
function identity_fake(array $routes): void
{
    Http::swap(new Factory());
    Http::preventStrayRequests();
    Http::fake($routes);
}

/**
 * A /v1/embeddings answer carrying one vector of length 3 (norm 1 by default).
 *
 * @param  list<float>  $vector
 */
function identity_embeddings_answer(?string $model = 'intfloat/multilingual-e5-small', array $vector = [0.6, 0.0, 0.8]): PromiseInterface
{
    return Http::response([
        'object' => 'list',
        ...($model === null ? [] : ['model' => $model]),
        'data' => [['object' => 'embedding', 'index' => 0, 'embedding' => $vector]],
    ]);
}

it('probes /v1/embeddings of the profile server and reports the answered model and the norm', function (): void {
    identity_fake([
        '*/v1/embeddings' => identity_embeddings_answer(),
        '*/info' => Http::response(['model_id' => 'intfloat/multilingual-e5-small', 'max_client_batch_size' => 32]),
    ]);

    $inspection = new EmbeddingServerIdentity()->inspect(identity_profile());

    expect($inspection->probeModel)->toBe('intfloat/multilingual-e5-small')
        ->and($inspection->norm)->toEqualWithDelta(1.0, 1e-9);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://tei.test:8080/v1/embeddings'
        && $request->hasHeader('Authorization', 'Bearer secret-key')
        && $request['model'] === 'intfloat/multilingual-e5-small'
        && $request['input'] === ['embedding service probe']);
});

it('reads /info model_id and max_client_batch_size when the server answers it and ignores a 404', function (): void {
    identity_fake([
        '*/v1/embeddings' => identity_embeddings_answer(),
        '*/info' => Http::response(['model_id' => 'intfloat/multilingual-e5-small', 'max_client_batch_size' => 16]),
    ]);

    $with = new EmbeddingServerIdentity()->inspect(identity_profile());

    expect($with->infoModel)->toBe('intfloat/multilingual-e5-small')
        ->and($with->maxClientBatchSize)->toBe(16);

    identity_fake([
        '*/v1/embeddings' => identity_embeddings_answer(),
        '*/info' => Http::response('not found', 404),
    ]);

    $without = new EmbeddingServerIdentity()->inspect(identity_profile());

    expect($without->infoModel)->toBeNull()->and($without->maxClientBatchSize)->toBeNull();
});

it('does not read /info when asked for the probe alone', function (): void {
    identity_fake(['*/v1/embeddings' => identity_embeddings_answer()]);

    new EmbeddingServerIdentity()->inspect(identity_profile(), 3, withInfo: false);

    Http::assertSentCount(1);
});

it('uses the timeout it is given for the probe and the server of the profile', function (): void {
    $timeouts = [];
    identity_fake(['*/v1/embeddings' => static function (Request $request, array $options) use (&$timeouts) {
        $timeouts[] = [$request->url(), $options['timeout'] ?? null];

        return identity_embeddings_answer();
    }]);

    new EmbeddingServerIdentity()->inspect(identity_profile(server: 'embeddings_other'), 3, withInfo: false);

    expect($timeouts)->toBe([['http://other.test:8081/v1/embeddings', 3]]);
});

it('refuses a vector of the wrong dimensions', function (): void {
    identity_fake(['*/v1/embeddings' => identity_embeddings_answer(vector: [0.1, 0.2]), '*/info' => Http::response([], 404)]);
    $identity = new EmbeddingServerIdentity();

    expect(fn () => $identity->inspect(identity_profile()))->toThrow(EmbeddingDimensionMismatch::class)
        ->and($identity->refusal(identity_profile()))->toContain('declares 3 dimensions')->toContain('2 dimensions');
});

it('refuses an unnormalised vector when the profile declares normalize, and does not check it otherwise', function (): void {
    identity_fake(['*/v1/embeddings' => identity_embeddings_answer(vector: [3.0, 0.0, 4.0]), '*/info' => Http::response([], 404)]);
    $identity = new EmbeddingServerIdentity();

    expect($identity->refusal(identity_profile(normalize: true)))->toContain('not normalised')->toContain('5.0')
        ->and($identity->refusal(identity_profile(normalize: false)))->toBeNull();
});

it('accepts a norm within the tolerance', function (): void {
    identity_fake(['*/v1/embeddings' => identity_embeddings_answer(vector: [0.6, 0.0, 0.8004]), '*/info' => Http::response([], 404)]);

    expect(new EmbeddingServerIdentity()->refusal(identity_profile()))->toBeNull();
});

it('refuses a server whose model is not the profile model, by the probe and by /info', function (): void {
    $identity = new EmbeddingServerIdentity();

    identity_fake(['*/v1/embeddings' => identity_embeddings_answer('sentence-transformers/all-MiniLM-L6-v2'), '*/info' => Http::response([], 404)]);
    expect($identity->refusal(identity_profile()))->toContain('/v1/embeddings reports model "sentence-transformers/all-MiniLM-L6-v2"')->toContain('expects "intfloat/multilingual-e5-small"');

    identity_fake(['*/v1/embeddings' => identity_embeddings_answer(), '*/info' => Http::response(['model_id' => 'BAAI/bge-m3'])]);
    expect($identity->refusal(identity_profile()))->toContain('/info reports model "BAAI/bge-m3"');
});

it('accepts the profile model with or without its organisation', function (): void {
    identity_fake(['*/v1/embeddings' => identity_embeddings_answer('multilingual-e5-small'), '*/info' => Http::response(['model_id' => 'intfloat/multilingual-e5-small'])]);

    expect(new EmbeddingServerIdentity()->refusal(identity_profile()))->toBeNull();
});

it('refuses a server that names no model for the switch, while inspect reports no model for the repair', function (): void {
    identity_fake(['*/v1/embeddings' => identity_embeddings_answer(null), '*/info' => Http::response([], 404)]);
    $identity = new EmbeddingServerIdentity();

    expect($identity->inspect(identity_profile())->reportedModel())->toBeNull()
        ->and($identity->refusal(identity_profile()))->toContain('does not report which model it runs');
});

it('prefers the model of the probe answer to the one of /info when reporting', function (): void {
    identity_fake(['*/v1/embeddings' => identity_embeddings_answer('probe-model'), '*/info' => Http::response(['model_id' => 'info-model'])]);

    expect(new EmbeddingServerIdentity()->inspect(identity_profile())->reportedModel())->toBe(['model' => 'probe-model', 'source' => '/v1/embeddings']);

    identity_fake(['*/v1/embeddings' => identity_embeddings_answer(null), '*/info' => Http::response(['model_id' => 'info-model'])]);

    expect(new EmbeddingServerIdentity()->inspect(identity_profile())->reportedModel())->toBe(['model' => 'info-model', 'source' => '/info']);
});

it('sends a request id to the probe and to /info, and attaches the probe\'s to its failure', function (): void {
    Log::spy();
    identity_fake(['*/v1/embeddings' => Http::response(['error' => 'internal'], 500), '*/info' => Http::response([], 404)]);

    try {
        new EmbeddingServerIdentity()->inspect(identity_profile());
        $this->fail('The probe should have failed.');
    } catch (SelfHostedServiceException $exception) {
        $id = Http::recorded()->first()[0]->header('X-Request-Id')[0];

        expect($id)->toMatch('/^[0-9a-f]{32}$/')
            ->and($exception->getMessage())->toContain("request_id {$id}")
            ->and($exception->context()['service'])->toBe('embeddings')
            ->and($exception->context()['status'])->toBe(500);

        Log::shouldHaveReceived('warning')->once()->with(Mockery::type('string'), Mockery::on(fn (array $context): bool => $context['request_id'] === $id));
    }

    identity_fake(['*/v1/embeddings' => identity_embeddings_answer(), '*/info' => Http::response([], 404)]);
    new EmbeddingServerIdentity()->inspect(identity_profile());

    $ids = Http::recorded()->map(fn (array $pair): string => $pair[0]->header('X-Request-Id')[0] ?? '')->all();

    expect($ids)->toHaveCount(2)->and($ids[0])->toMatch('/^[0-9a-f]{32}$/')->and($ids[1])->toMatch('/^[0-9a-f]{32}$/');
});

it('says the server cannot be reached, with the endpoint and no credentials, when the probe fails to connect', function (): void {
    config(['ai.servers.embeddings.url' => 'http://user:url-password@tei.test:8080']);
    identity_fake(['*/v1/embeddings' => static fn () => throw new ConnectionException('refused'), '*/info' => Http::response([], 404)]);

    $refusal = new EmbeddingServerIdentity()->refusal(identity_profile());

    expect($refusal)->toContain('probe failed')->toContain('http://tei.test:8080/v1/embeddings')->not->toContain('url-password');
});

it('refuses a profile whose server has no url', function (): void {
    config(['ai.servers.embeddings.url' => '']);

    expect(new EmbeddingServerIdentity()->refusal(identity_profile()))->toContain('has no URL');
});
