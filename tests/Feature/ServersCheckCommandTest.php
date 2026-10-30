<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::preventStrayRequests();
    config()->set('ai.servers', [
        'embeddings' => ['url' => 'http://tei.test:8080', 'api_key' => null, 'timeout' => 30, 'batch_size' => 32],
        'rerank' => ['url' => '', 'api_key' => null, 'dialect' => 'tei', 'model' => null],
        'transcription' => ['url' => '', 'api_key' => null, 'timeout' => 120],
    ]);
    config()->set('ai.features.embeddings.serving_model', 'openai_compatible:intfloat/multilingual-e5-small');
    config()->set('ai.features.embeddings.models', [
        'openai_compatible:intfloat/multilingual-e5-small' => ['dimensions' => 384, 'normalize' => true],
    ]);
});

it('prints a table of the configured servers and exits 0 when every probe passes', function (): void {
    Http::fake([
        'tei.test:8080/v1/embeddings' => Http::response(['model' => 'intfloat/multilingual-e5-small', 'data' => [['index' => 0, 'embedding' => ai_test_unit_vector(384)]]]),
        'tei.test:8080/info' => Http::response(['model_id' => 'intfloat/multilingual-e5-small', 'max_client_batch_size' => 32]),
    ]);

    $exit = Artisan::call('ai:servers:check');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('embeddings')->toContain('http://tei.test:8080')->toContain('OK')->toContain('384 dimensions')
        ->and($output)->not->toContain('FAILED');
});

it('exits 1 when a configured server fails its probe', function (): void {
    Http::fake([
        'tei.test:8080/v1/embeddings' => Http::response(['model' => 'BAAI/bge-m3', 'data' => [['index' => 0, 'embedding' => ai_test_unit_vector(384)]]]),
        'tei.test:8080/info' => Http::response('not found', 404),
    ]);

    $exit = Artisan::call('ai:servers:check');

    expect($exit)->toBe(1)->and(Artisan::output())->toContain('FAILED')->toContain('BAAI/bge-m3');
});

it('never prints the credentials of a url', function (): void {
    config()->set('ai.servers.embeddings.url', 'http://user:url-password@tei.test:8080');
    Http::fake([
        'tei.test:8080/v1/embeddings' => Http::response(['model' => 'intfloat/multilingual-e5-small', 'data' => [['index' => 0, 'embedding' => ai_test_unit_vector(384)]]]),
        'tei.test:8080/info' => Http::response('not found', 404),
    ]);

    Artisan::call('ai:servers:check');

    expect(Artisan::output())->toContain('http://tei.test:8080')->not->toContain('url-password');
});

it('says no server is configured and exits 0 when there is none', function (): void {
    config()->set('ai.servers.embeddings.url', '');

    $exit = Artisan::call('ai:servers:check');

    expect($exit)->toBe(0)->and(Artisan::output())->toContain('No AI server is configured');
});
