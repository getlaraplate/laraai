<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\AI\Ai\Providers\Models\ModelCatalog;
use Modules\AI\Ai\Providers\Models\OpenAiModelLister;
use Modules\AI\Enums\AiModelFeature;
use Modules\AI\Enums\ProviderListingStatus;

beforeEach(function (): void {
    foreach (['openai', 'anthropic', 'mistral'] as $provider) {
        config()->set("ai.providers.{$provider}.api_key", '');
    }

    config()->set('core.deepl_api_key', '');
    config()->set('ai.providers.openai.base_url', '');
    config()->set('ai.providers.ollama.api_url', '');
    config()->set('ai.servers.embeddings.url', '');
    config()->set('ai.servers.transcription.url', '');
});

it('drops the entries of a provider that is not configured', function (): void {
    $result = app(ModelCatalog::class)->build([AiModelFeature::Chat], ['features.chat.model' => ['openai:gpt-4o']]);

    expect($result->choices['features.chat.model'])->toBe([])
        ->and($result->outcomes['openai']->status)->toBe(ProviderListingStatus::NotConfigured)
        ->and($result->hasFailures())->toBeFalse();
});

it('keeps the previous entries of a configured provider that fails', function (): void {
    config()->set('ai.providers.anthropic.api_key', 'ak-test');
    Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);

    $result = app(ModelCatalog::class)->build(
        [AiModelFeature::Chat],
        ['features.chat.model' => ['anthropic:claude-opus-5', 'openai:gpt-4o']],
    );

    expect($result->choices['features.chat.model'])->toBe(['anthropic:claude-opus-5'])
        ->and($result->outcomes['anthropic']->status)->toBe(ProviderListingStatus::Failed)
        ->and($result->hasFailures())->toBeTrue();
});

it('treats a timeout like any other failure', function (): void {
    config()->set('ai.providers.ollama.api_url', 'http://ollama.test');
    Http::fake(['ollama.test/*' => Http::failedConnection('Operation timed out')]);

    $result = app(ModelCatalog::class)->build([AiModelFeature::Faq], ['features.faq.model' => ['ollama:llama3.2:3b']]);

    expect($result->choices['features.faq.model'])->toBe(['ollama:llama3.2:3b'])
        ->and($result->outcomes['ollama']->status)->toBe(ProviderListingStatus::Failed)
        ->and($result->outcomes['ollama']->error)->toContain('timed out');
});

it('reports an empty listing as listed, not failed', function (): void {
    config()->set('ai.providers.ollama.api_url', 'http://ollama.test');
    Http::fake(['ollama.test/api/tags' => Http::response(['models' => []])]);

    $result = app(ModelCatalog::class)->build([AiModelFeature::Chat], ['features.chat.model' => ['ollama:gone:1b']]);

    expect($result->choices['features.chat.model'])->toBe([])
        ->and($result->outcomes['ollama']->status)->toBe(ProviderListingStatus::Listed)
        ->and($result->outcomes['ollama']->modelCount)->toBe(0)
        ->and($result->hasFailures())->toBeFalse();
});

it('filters by the capabilities each feature requires and calls each provider once', function (): void {
    config()->set('ai.providers.mistral.api_key', 'mk-test');
    config()->set('ai.providers.openai.api_key', 'sk-test');
    Http::fake([
        'api.mistral.ai/v1/models' => Http::response(['data' => [
            ['id' => 'mistral-large-latest', 'capabilities' => ['completion_chat' => true, 'function_calling' => true, 'vision' => false]],
            ['id' => 'mistral-small-no-tools', 'capabilities' => ['completion_chat' => true, 'function_calling' => false, 'vision' => false]],
        ]]),
        'api.openai.com/v1/models' => Http::response(['data' => [['id' => 'gpt-4o'], ['id' => 'text-embedding-3-small']]]),
    ]);

    $result = app(ModelCatalog::class)->build(
        [AiModelFeature::Chat, AiModelFeature::TextGeneration, AiModelFeature::Vision],
        [],
    );

    expect($result->choices['features.chat.model'])->toBe(['mistral:mistral-large-latest', 'openai:gpt-4o'])
        ->and($result->choices['features.text_generation.model'])->toBe(['mistral:mistral-large-latest', 'mistral:mistral-small-no-tools', 'openai:gpt-4o'])
        ->and($result->choices['features.media_analysis.vision.model'])->toBe(['openai:gpt-4o']);

    Http::assertSentCount(2);
});

it('offers the configured transcription models of the OpenAI-compatible provider, and deepl by name, when configured', function (): void {
    Http::fake();
    config()->set('core.deepl_api_key', 'dk-test');
    config()->set('ai.servers.transcription.url', 'http://speaches.test');
    config()->set('ai.features.media_analysis.transcription.models', ['Systran/faster-whisper-small', 'Systran/faster-whisper-base']);

    $result = app(ModelCatalog::class)->build([AiModelFeature::Translation, AiModelFeature::Transcription], []);

    expect($result->choices['features.translation.model'])->toBe(['deepl'])
        ->and($result->choices['features.media_analysis.transcription.model'])->toBe(['openai_compatible:Systran/faster-whisper-base', 'openai_compatible:Systran/faster-whisper-small']);

    Http::assertNothingSent();
});

it('keeps the previous entries when a provider answers with something that is not a model list', function (): void {
    config()->set('ai.providers.mistral.api_key', 'mk-test');
    Http::fake(['api.mistral.ai/*' => Http::response('<html>maintenance</html>', 200)]);

    $result = app(ModelCatalog::class)->build(
        [AiModelFeature::TextGeneration],
        ['features.text_generation.model' => ['mistral:mistral-large-latest']],
    );

    expect($result->choices['features.text_generation.model'])->toBe(['mistral:mistral-large-latest'])
        ->and($result->outcomes['mistral']->status)->toBe(ProviderListingStatus::Failed);
});

it('keeps every model of an OpenAI-compatible server behind a base URL', function (): void {
    Http::fake(['localhost:8080/v1/models' => Http::response(['data' => [['id' => 'qwen2.5-1.5b-instruct-q4_k_m.gguf']]])]);

    $models = (new OpenAiModelLister('', 'http://localhost:8080/v1'))->list();

    expect(array_map(static fn ($m) => $m->id, $models))->toBe(['qwen2.5-1.5b-instruct-q4_k_m.gguf']);
});

it('offers no transcription model when only an embeddings server is configured', function (): void {
    Http::fake();
    config()->set('ai.servers.embeddings.url', 'http://tei.test:8080');
    config()->set('ai.servers.transcription.url', '');

    $result = app(ModelCatalog::class)->build([AiModelFeature::Transcription], []);

    expect($result->choices['features.media_analysis.transcription.model'])->toBe([]);
});
