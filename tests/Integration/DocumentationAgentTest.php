<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\DocumentationAgent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;

it('can be instantiated via make', function (): void {
    $agent = DocumentationAgent::make();

    expect($agent)->toBeInstanceOf(DocumentationAgent::class);
});

it('instructions returns documentation system prompt', function (): void {
    $agent = DocumentationAgent::make();

    $reflection = new ReflectionMethod($agent, 'instructions');
    $instructions = $reflection->invoke($agent);

    expect($instructions->getContent())->toContain('documentation assistant')
        ->and($instructions->getContent())->toContain('context documents');
});

it('provider returns AIProviderInterface', function (): void {
    config()->set('ai.features.faq.model', 'ollama:llama3.2:3b');
    config()->set('ai.providers.ollama.api_url', 'http://localhost:11434');

    $agent = DocumentationAgent::make();

    $reflection = new ReflectionMethod($agent, 'provider');
    $provider = $reflection->invoke($agent);

    expect($provider)->toBeInstanceOf(AIProviderInterface::class);
});

it('vectorStore returns VectorStoreInterface with memory driver', function (): void {
    config()->set('ai.features.faq.vector_store', 'memory');

    $agent = DocumentationAgent::make(vectorStoreDriver: 'memory');

    $reflection = new ReflectionMethod($agent, 'vectorStore');
    $vectorStore = $reflection->invoke($agent);

    expect($vectorStore)->toBeInstanceOf(VectorStoreInterface::class);
});
