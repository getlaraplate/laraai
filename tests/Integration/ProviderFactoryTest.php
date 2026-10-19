<?php

declare(strict_types=1);

use Modules\AI\Ai\Providers\ProviderFactory;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\OpenAI;

it('creates an OpenAI provider when configured', function (): void {
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.openai.model', 'gpt-4o-mini');

    $provider = ProviderFactory::make('openai');

    expect($provider)->toBeInstanceOf(OpenAI::class);
});

it('creates an Ollama provider when configured', function (): void {
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set('ai.providers.ollama.model', 'llama3.2:3b');

    $provider = ProviderFactory::make('ollama');

    expect($provider)->toBeInstanceOf(Ollama::class);
});

it('creates a Mistral provider when configured', function (): void {
    config()->set('ai.providers.mistral.api_key', 'test-key');
    config()->set('ai.providers.mistral.model', 'mistral-large-latest');

    $provider = ProviderFactory::make('mistral');

    expect($provider)->toBeInstanceOf(Mistral::class);
});

it('creates an Anthropic provider when configured', function (): void {
    config()->set('ai.providers.anthropic.api_key', 'test-key');

    $provider = ProviderFactory::make('anthropic');

    expect($provider)->toBeInstanceOf(Anthropic::class);
});

it('throws exception for unsupported provider', function (): void {
    ProviderFactory::make('non-existent');
})->throws(Exception::class, 'Unsupported AI provider: non-existent');

it('uses default provider from config when none specified', function (): void {
    config()->set('ai.features.chat.model', 'ollama:llama3.2:3b');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');
    config()->set('ai.providers.ollama.model', 'llama3.2:3b');

    $provider = ProviderFactory::make();

    expect($provider)->toBeInstanceOf(Ollama::class);
});

it('throws when OpenAI API key is missing', function (): void {
    config()->set('ai.providers.openai.api_key', '');

    ProviderFactory::make('openai');
})->throws(Exception::class, 'OpenAI API key is not configured');

it('throws when Mistral API key is missing', function (): void {
    config()->set('ai.providers.mistral.api_key', '');

    ProviderFactory::make('mistral');
})->throws(Exception::class, 'Mistral API key is not configured');

it('throws when Anthropic API key is missing', function (): void {
    config()->set('ai.providers.anthropic.api_key', '');

    ProviderFactory::make('anthropic');
})->throws(Exception::class, 'Anthropic API key is not configured');

it('throws when the Ollama URL is missing', function (): void {
    config()->set('ai.providers.ollama.api_url', null);

    ProviderFactory::make('ollama');
})->throws(Modules\Core\Exceptions\ConfigurationException::class, 'Ollama API URL is not configured');

it('points chat at the Ollama api path of the configured base URL', function (): void {
    config()->set('ai.providers.ollama.api_url', 'http://ollama.test/');

    $provider = ProviderFactory::make('ollama', 'llama3.2:3b');

    expect((new ReflectionProperty($provider, 'baseUri'))->getValue($provider))->toBe('http://ollama.test/api');
});

it('caps the output of each provider with the parameter it names it by', function (): void {
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.mistral.api_key', 'test-key');
    config()->set('ai.providers.anthropic.api_key', 'test-key');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');

    $read = static fn (object $provider, string $property): mixed => new ReflectionProperty($provider, $property)->getValue($provider);

    expect($read(ProviderFactory::make('openai', null, 60), 'parameters'))->toBe(['max_completion_tokens' => 60])
        ->and($read(ProviderFactory::make('mistral', null, 60), 'parameters'))->toBe(['max_tokens' => 60])
        ->and($read(ProviderFactory::make('ollama', null, 60), 'parameters'))->toBe(['options' => ['num_predict' => 60]])
        ->and($read(ProviderFactory::make('anthropic', null, 60), 'max_tokens'))->toBe(60);
});

it('leaves the limit of the provider alone when no cap is asked for', function (): void {
    config()->set('ai.providers.openai.api_key', 'test-key');
    config()->set('ai.providers.mistral.api_key', 'test-key');
    config()->set('ai.providers.anthropic.api_key', 'test-key');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');

    $read = static fn (object $provider, string $property): mixed => new ReflectionProperty($provider, $property)->getValue($provider);

    expect($read(ProviderFactory::make('openai'), 'parameters'))->toBe([])
        ->and($read(ProviderFactory::make('mistral'), 'parameters'))->toBe([])
        ->and($read(ProviderFactory::make('ollama'), 'parameters'))->toBe([])
        ->and($read(ProviderFactory::make('anthropic'), 'max_tokens'))->toBe(8192);
});
