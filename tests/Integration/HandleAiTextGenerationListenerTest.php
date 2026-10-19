<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Listeners\HandleAiTextGenerationListener;
use Modules\Core\Events\AiTextGenerationRequested;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;

beforeEach(function (): void {
    config()->set('ai.features.text_generation.enabled', true);
});

/**
 * A listener whose model is Neuron's fake provider, answering with the given replies in order; with
 * none, it fails like a provider that is down. What it was asked is read from $provider.
 *
 * @param  list<string>  $replies
 */
function textGenerationListener(array $replies, ?FakeAIProvider &$provider = null): HandleAiTextGenerationListener
{
    $provider = new FakeAIProvider(...array_map(static fn (string $reply): AssistantMessage => new AssistantMessage($reply), $replies));
    $model = $provider;

    return new HandleAiTextGenerationListener(chatAgentFactory: static fn (): ChatAgent => ChatAgent::make(systemPrompt: 'Rewrite.')->setAiProvider($model));
}

it('leaves the request unfulfilled when the feature is disabled', function (): void {
    config()->set('ai.features.text_generation.enabled', false);

    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    new HandleAiTextGenerationListener()->handle($event);

    expect($event->isFulfilled())->toBeFalse();
});

it('fulfils the request with the generated text when enabled, asking the model for the prompt of the event', function (): void {
    $listener = textGenerationListener(['Ada Lovelace owns this area.'], $provider);

    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    $listener->handle($event);

    expect($event->response)->toBe('Ada Lovelace owns this area.')
        ->and($event->isFulfilled())->toBeTrue()
        ->and((string) $provider->getRecorded()[0]->messages[0]->getContent())->toBe('rewrite this')
        ->and($provider->getRecorded()[0]->systemPrompt?->getContent())->toBe('Rewrite.');
});

it('does not overwrite an already-fulfilled request, and does not call the model', function (): void {
    $listener = textGenerationListener(['should not be used'], $provider);

    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    $event->fulfill('already done');
    $listener->handle($event);

    expect($event->response)->toBe('already done');

    $provider->assertNothingSent();
});

it('leaves the request unfulfilled when the model returns empty text', function (): void {
    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    textGenerationListener([''])->handle($event);

    expect($event->isFulfilled())->toBeFalse();
});

it('leaves the request unfulfilled when the model throws', function (): void {
    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    textGenerationListener([])->handle($event);

    expect($event->isFulfilled())->toBeFalse();
});

it('no-ops once the per-purpose rate limit is exhausted', function (): void {
    config()->set('ai.features.text_generation.rate_limit.max', 1);
    config()->set('ai.features.text_generation.rate_limit.per_seconds', 60);

    $listener = textGenerationListener(['Ada owns this.', 'Ada owns that.']);

    $first = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    $second = new AiTextGenerationRequested('rewrite that', 'sao.ownership_suggestion');
    $listener->handle($first);
    $listener->handle($second);

    expect($first->isFulfilled())->toBeTrue()
        ->and($second->isFulfilled())->toBeFalse();
});

it('serves a cached response without calling the model again', function (): void {
    config()->set('ai.features.text_generation.cache_ttl_seconds', 60);

    $prompt = 'rewrite this';
    textGenerationListener(['Ada owns this.'])->handle(new AiTextGenerationRequested($prompt, 'sao.ownership_suggestion'));

    // A second listener whose model is down still resolves from the cache, and never calls it.
    $second = new AiTextGenerationRequested($prompt, 'sao.ownership_suggestion');
    $listener = textGenerationListener([], $provider);
    $listener->handle($second);

    expect($second->response)->toBe('Ada owns this.');

    $provider->assertNothingSent();
});

it('caps the output on a word boundary', function (): void {
    config()->set('ai.features.text_generation.max_output_chars', 20);

    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    textGenerationListener(['one two three four five six seven'])->handle($event);

    expect(mb_strlen((string) $event->response))->toBeLessThanOrEqual(20)
        ->and($event->response)->not->toEndWith(' ');
});

it('strips control characters and collapses whitespace', function (): void {
    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    textGenerationListener(["Ada\n\n  Lovelace\towns\x07 this."])->handle($event);

    expect($event->response)->toBe('Ada Lovelace owns this.');
});

it('logs the outcome of an attempt', function (): void {
    Log::spy();

    $event = new AiTextGenerationRequested('rewrite this', 'sao.ownership_suggestion');
    textGenerationListener(['Ada owns this.'])->handle($event);

    Log::shouldHaveReceived('info')->withArgs(
        static fn (string $message, array $context): bool => $message === 'ai.text_generation'
            && ($context['outcome'] ?? null) === 'fulfilled'
            && ($context['purpose'] ?? null) === 'sao.ownership_suggestion',
    )->once();
});
