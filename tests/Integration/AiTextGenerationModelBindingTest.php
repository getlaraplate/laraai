<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Ai\Providers\ProviderFactory;
use Modules\AI\Listeners\HandleAiTextGenerationListener;
use Modules\AI\Tests\Stubs\RecordingHttpClient;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\Ollama\Ollama;

beforeEach(function (): void {
    config()->set('ai.providers.ollama.api_url', 'http://ollama.test');
});

it('binds the requested model and marshals the provider call over faked HTTP', function (): void {
    $client = new RecordingHttpClient(['message' => ['content' => 'Ada Lovelace owns this area.']]);

    $provider = ProviderFactory::make('ollama', 'phi3');
    expect($provider)->toBeInstanceOf(Ollama::class);

    $provider->setHttpClient($client);

    $message = $provider->chat(new UserMessage('rewrite this'));

    expect($message->getContent())->toBe('Ada Lovelace owns this area.')
        ->and($client->lastRequest)->not->toBeNull()
        ->and($client->lastRequest->uri)->toBe('http://ollama.test/api/chat')
        ->and($client->lastRequest->body['model'])->toBe('phi3');
});

it('falls back to the provider default model when none is requested', function (): void {
    config()->set('ai.providers.ollama.model', 'llama3.2:3b');

    $client = new RecordingHttpClient(['message' => ['content' => 'ok']]);
    $provider = ProviderFactory::make('ollama');
    $provider->setHttpClient($client);

    $provider->chat(new UserMessage('hi'));

    expect($client->lastRequest->body['model'])->toBe('llama3.2:3b');
});

it('builds the text-generation chat agent on the configured model', function (): void {
    config()->set('ai.features.text_generation.model', 'ollama:phi3');

    $agent = invokeMakeChatAgent(new HandleAiTextGenerationListener());

    expect(readChatAgentModel($agent))->toBe('phi3');
});

it('leaves the chat agent model null when the feature configures none', function (): void {
    config()->set('ai.features.text_generation.model', 'ollama');

    $agent = invokeMakeChatAgent(new HandleAiTextGenerationListener());

    expect(readChatAgentModel($agent))->toBeNull();
});

function invokeMakeChatAgent(HandleAiTextGenerationListener $listener): ChatAgent
{
    $method = new ReflectionMethod($listener, 'makeChatAgent');

    /** @var ChatAgent */
    return $method->invoke($listener);
}

function readChatAgentModel(ChatAgent $agent): ?string
{
    $property = new ReflectionProperty(ChatAgent::class, 'model');

    /** @var string|null */
    return $property->getValue($agent);
}
