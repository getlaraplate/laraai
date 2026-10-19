<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\ChatAgent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Testing\FakeAIProvider;

it('gives every agent its own workflow id', function (): void {
    $first = ChatAgent::make(providerName: 'ollama');
    $second = ChatAgent::make(providerName: 'ollama');

    expect($first->getWorkflowId())->toBeString()->not->toBeEmpty()
        ->and($second->getWorkflowId())->toBeString()->not->toBe($first->getWorkflowId());
});

it('runs a chat on an agent that has a workflow id', function (): void {
    $agent = ChatAgent::make(providerName: 'ollama', systemPrompt: 'Be brief.');
    $agent->setAiProvider($provider = new FakeAIProvider(new AssistantMessage('Hello back.')));

    $answer = $agent->chat(new UserMessage('Hello'))->getMessage()->getContent();

    expect($answer)->toBe('Hello back.');
    $provider->assertCallCount(1);
});

it('creates a ChatAgent via static make', function (): void {
    config()->set('ai.features.chat.model', 'ollama:llama3.2:3b');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set('ai.providers.ollama.model', 'llama3.2:3b');

    $agent = ChatAgent::make();

    expect($agent)->toBeInstanceOf(ChatAgent::class);
});

it('uses default system prompt when none provided', function (): void {
    config()->set('ai.features.chat.model', 'ollama:llama3.2:3b');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');

    $agent = ChatAgent::make();

    $reflection = new ReflectionMethod($agent, 'instructions');
    $instructions = $reflection->invoke($agent);

    expect($instructions)->toBe('You are a helpful AI assistant.');
});

it('uses custom system prompt when provided', function (): void {
    config()->set('ai.features.chat.model', 'ollama:llama3.2:3b');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');

    $agent = ChatAgent::make(systemPrompt: 'You are a translator.');

    $reflection = new ReflectionMethod($agent, 'instructions');
    $instructions = $reflection->invoke($agent);

    expect($instructions)->toBe('You are a translator.');
});

it('resolves provider via ProviderFactory', function (): void {
    config()->set('ai.features.chat.model', 'ollama:llama3.2:3b');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set('ai.providers.ollama.model', 'llama3.2:3b');

    $agent = ChatAgent::make();

    $reflection = new ReflectionMethod($agent, 'provider');
    $provider = $reflection->invoke($agent);

    expect($provider)->toBeInstanceOf(AIProviderInterface::class);
});

it('resolves specific provider when name is given', function (): void {
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.openai.model', 'gpt-4o-mini');

    $agent = ChatAgent::make(providerName: 'openai');

    $reflection = new ReflectionMethod($agent, 'provider');
    $provider = $reflection->invoke($agent);

    expect($provider)->toBeInstanceOf(AIProviderInterface::class);
});

/**
 * What the agent's tool error handler answers the model for an exception.
 */
function chatAgentToolErrorFor(Throwable $exception): string
{
    $agent = ChatAgent::make(systemPrompt: 'x');
    $handler = (new ReflectionMethod($agent, 'resolveToolErrorHandler'))->invoke($agent);

    return $handler($exception, NeuronAI\Tools\ToolCall::make(name: 'lookup', callId: 'call_1', inputs: []));
}

it('answers the model with a fixed message when a tool fails, never with the exception text', function (): void {
    $message = chatAgentToolErrorFor(new NeuronAI\Exceptions\MissingCallbackParameter('Missing required parameter: secret_column'));

    expect($message)->toContain('The tool call failed')->not->toContain('secret_column')->not->toContain('Missing required');
});

it('tells the model a tool has been called too often', function (): void {
    $message = chatAgentToolErrorFor(new NeuronAI\Exceptions\ToolRunsExceededException('Tool lookup has been executed too many times - 3 - with arguments: {"id":7}'));

    expect($message)->toContain('too many times')->not->toContain('"id"');
});

it('lets a policy violation end the turn instead of handing it to the model', function (): void {
    chatAgentToolErrorFor(new Modules\AI\Exceptions\AssistancePolicyViolationException('unsafe_output'));
})->throws(Modules\AI\Exceptions\AssistancePolicyViolationException::class);

it('caps the seconds of one call on the provider it builds', function (): void {
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');

    $agent = ChatAgent::make(providerName: 'ollama')->withTimeout(7.0);
    $provider = (new ReflectionMethod($agent, 'provider'))->invoke($agent);
    $client = $provider->getHttpClient();

    expect((new ReflectionProperty($client, 'timeout'))->getValue($client))->toBe(7.0);
});

it('leaves the provider timeout alone when none is asked for', function (): void {
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');

    $agent = ChatAgent::make(providerName: 'ollama');
    $provider = (new ReflectionMethod($agent, 'provider'))->invoke($agent);
    $client = $provider->getHttpClient();

    expect((new ReflectionProperty($client, 'timeout'))->getValue($client))->toBe(60.0);
});
