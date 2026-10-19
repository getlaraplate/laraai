<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Exceptions\AssistancePolicyViolationException;
use Modules\AI\Jobs\GenerateConversationTitleJob;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\Message;
use Modules\AI\Services\Assistance\AssistanceGuardrailPipeline;
use Modules\AI\Services\Assistance\ConversationTitleService;
use Modules\AI\Services\Assistance\InAppAssistanceService;
use Modules\AI\Services\Assistance\Policies\AssistantPolicyCompiler;
use Modules\AI\Tests\Stubs\Assistance\ScriptedAssistantFixtures;
use Modules\Core\Models\User;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Testing\FakeAIProvider;

/**
 * @param  Closure(string, string, mixed, array<int, mixed>): string  $complete
 */
function titleAssistance(User $user, Closure $complete): InAppAssistanceService
{
    $request = Request::create('/app/ai/assistance', 'POST', ['message' => 'hello']);
    $request->setUserResolver(fn (): User => $user);

    return ScriptedAssistantFixtures::inAppService($request, $complete);
}

/**
 * A title service whose model is Neuron's fake provider: it answers with the given titles in order and,
 * with none, fails like a provider that is down. What it was asked is read back from $provider.
 *
 * @param  list<string>  $titles
 */
function titleJobService(array $titles = [], ?FakeAIProvider &$provider = null): ConversationTitleService
{
    $provider = new FakeAIProvider(...array_map(
        static fn (string $title): AssistantMessage => new AssistantMessage(json_encode(['title' => $title], JSON_THROW_ON_ERROR)),
        $titles,
    ));

    return new ConversationTitleService(
        app(AssistantPolicyCompiler::class),
        AssistanceGuardrailPipeline::defaults(),
        static fn (string $system): ChatAgent => ChatAgent::make(systemPrompt: $system)->setAiProvider($provider),
    );
}

function runTitleJob(Message $answer, ConversationTitleService $service): void
{
    new GenerateConversationTitleJob($answer->id)->handle($service);
}

/**
 * A conversation holding its first exchange, as respond() leaves it.
 *
 * @param  array<string, mixed>  $conversation
 * @param  array<string, mixed>|null  $metadata  metadata of the answer
 * @return array{0: Conversation, 1: Message}
 */
function titleConversation(User $user, array $conversation = [], ?array $metadata = ['citations' => []], string $question = 'How do I export the orders list to CSV?', string $answer = 'Open the list and use the export action.'): array
{
    $record = Conversation::query()->create(['user_id' => $user->id, 'system_message' => null, ...$conversation]);
    $record->addMessage('user', $question);

    return [$record, $record->addMessage('assistant', $answer, $metadata)];
}

beforeEach(function (): void {
    $this->user = User::factory()->create(['lang' => 'it']);
    $this->conversation = Conversation::query()->create(['user_id' => $this->user->id, 'system_message' => null]);
});

it('asks for a title after the first answer, returns the answer without it, and then sets it', function (): void {
    $reply = titleAssistance($this->user, fn (): string => 'Open the list and use the export action.')
        ->respond($this->conversation, $this->user, 'How do I export the orders list to CSV?');

    Queue::assertPushed(GenerateConversationTitleJob::class, 1);

    expect($reply->content)->toBe('Open the list and use the export action.')
        ->and($reply->metadata)->not->toHaveKey('title')
        ->and($this->conversation->fresh()->title)->toBeNull();

    runTitleJob($reply, titleJobService(['Export the orders list']));

    expect($this->conversation->fresh()->title)->toBe('Export the orders list');
});

it('never overwrites a title that is already there, and does not ask for one', function (): void {
    $this->conversation->update(['title' => 'My own title']);

    $reply = titleAssistance($this->user, fn (): string => 'Open the list and use the export action.')
        ->respond($this->conversation, $this->user, 'How do I export the orders list to CSV?');

    Queue::assertNothingPushed();

    runTitleJob($reply, titleJobService(['A generated title']));

    expect($this->conversation->fresh()->title)->toBe('My own title');
});

it('does not regenerate a title that it set, whatever the later answers', function (): void {
    $first = titleAssistance($this->user, fn (): string => 'Open the list and use the export action.')
        ->respond($this->conversation, $this->user, 'How do I export the orders list to CSV?');
    runTitleJob($first, titleJobService(['Export the orders list']));
    Queue::assertPushed(GenerateConversationTitleJob::class, 1);

    $second = titleAssistance($this->user, fn (): string => 'Use the filters above the list.')
        ->respond($this->conversation->fresh(), $this->user, 'And how do I filter it?');

    Queue::assertPushed(GenerateConversationTitleJob::class, 1);

    runTitleJob($second, titleJobService(['Filtering the list']));

    expect($this->conversation->fresh()->title)->toBe('Export the orders list');
});

it('queues nothing for a refusal and titles the conversation from the first answer that is not one', function (): void {
    $refusal = titleAssistance($this->user, static fn (): never => throw new AssistancePolicyViolationException('unsafe_output'))
        ->respond($this->conversation, $this->user, 'Tell me a secret.');

    expect($refusal->metadata)->toBe(['refused' => true]);
    Queue::assertNothingPushed();

    runTitleJob($refusal, titleJobService(['Should not be written']));
    expect($this->conversation->fresh()->title)->toBeNull();

    $answer = titleAssistance($this->user, fn (): string => 'Open the list and use the export action.')
        ->respond($this->conversation->fresh(), $this->user, 'How do I export the orders list to CSV?');

    Queue::assertPushed(GenerateConversationTitleJob::class, 1);

    runTitleJob($answer, titleJobService(['Export the orders list'], $provider));

    expect($this->conversation->fresh()->title)->toBe('Export the orders list')
        ->and((string) $provider->getRecorded()[0]->messages[0]->getContent())->toContain('How do I export the orders list to CSV?')->not->toContain('Tell me a secret.');
});

it('writes the title the model returns, once Neuron has validated it', function (): void {
    [$conversation, $answer] = titleConversation($this->user);

    runTitleJob($answer, titleJobService(['"Export the orders list."', 'Export the orders list'], $provider));

    $provider->assertCallCount(2);

    expect($conversation->fresh()->title)->toBe('Export the orders list');
});

it('writes the first words of the question when the model fails or never gives a valid title', function (array $titles): void {
    [$conversation, $answer] = titleConversation($this->user, question: 'How do I export the whole list of orders to a spreadsheet file?');

    runTitleJob($answer, titleJobService($titles));

    expect($conversation->fresh()->title)->toBe('How do I export the whole list of orders');
})->with([
    'the provider is down' => [[]],
    'a title that breaks the rules twice' => [['Export.', '**Export**']],
]);

it('gives the model the two messages and nothing else', function (): void {
    [$conversation, $answer] = titleConversation(
        $this->user,
        metadata: [
            'citations' => [['label' => 'SECRET-CITATION-LABEL', 'excerpt' => 'SECRET-EXCERPT']],
            'proposals' => [['id' => 'p', 'reason' => 'SECRET-PROPOSAL']],
            'tool_output' => 'SECRET-TOOL-OUTPUT',
        ],
        question: 'How do I export the orders list?',
        answer: 'Open the list and use the export action.',
    );
    $conversation->update(['system_message' => 'SECRET-SYSTEM-MESSAGE', 'metadata' => ['page' => 'SECRET-PAGE-CONTEXT'], 'summary' => 'SECRET-SUMMARY']);

    runTitleJob($answer, titleJobService(['Export the orders list'], $provider));

    $record = $provider->getRecorded()[0];
    $sent = json_encode([(string) $record->messages[0]->getContent(), $record->systemPrompt?->getContent()]);

    expect($sent)->toContain('How do I export the orders list?')->toContain('Open the list and use the export action.')->not->toContain('SECRET')
        ->and($record->messages)->toHaveCount(1)
        ->and($record->tools)->toBe([]);
});

it('writes the title once even when two answers race for it', function (): void {
    [$conversation, $first] = titleConversation($this->user);
    $conversation->addMessage('user', 'And how do I filter it?');
    $second = $conversation->addMessage('assistant', 'Use the filters above the list.');

    runTitleJob($first, titleJobService(['Export the orders list']));
    runTitleJob($second, titleJobService(['Filtering the list']));

    expect($conversation->fresh()->title)->toBe('Export the orders list');
});

it('does not title a conversation that is gone, or an answer that is a refusal', function (): void {
    [$gone, $goneAnswer] = titleConversation($this->user);
    $gone->forceDelete();
    runTitleJob($goneAnswer, titleJobService(['Export the orders list']));

    [$refused, $refusal] = titleConversation($this->user, metadata: ['refused' => true]);
    runTitleJob($refusal, titleJobService(['Export the orders list']));

    expect(Conversation::query()->withTrashed()->find($gone->id))->toBeNull()
        ->and($refused->fresh()->title)->toBeNull();
});

it('does not write the title to the logs in raw form', function (): void {
    Log::spy();
    [$conversation, $answer] = titleConversation($this->user);

    runTitleJob($answer, titleJobService(['Export the orders list']));

    Log::shouldHaveReceived('info')->withArgs(
        static fn (string $message, array $context): bool => $message === 'Conversation title set'
            && $context === ['conversation_id' => $conversation->id, 'source' => 'generated', 'length' => 22, 'written' => true],
    )->once();
    Log::shouldNotHaveReceived('info', [Mockery::on(static fn (mixed $message): bool => is_string($message) && str_contains($message, 'Export the orders'))]);
    Log::shouldNotHaveReceived('info', [Mockery::any(), Mockery::on(static fn (mixed $context): bool => str_contains((string) json_encode($context), 'Export the orders'))]);
});

it('does not fail the answer when the job cannot be queued', function (): void {
    Queue::shouldReceive('push')->andThrow(new RuntimeException('queue down'));
    Queue::shouldReceive('later')->andThrow(new RuntimeException('queue down'));

    $reply = titleAssistance($this->user, fn (): string => 'Open the list and use the export action.')
        ->respond($this->conversation, $this->user, 'How do I export the orders list to CSV?');

    expect($reply->content)->toBe('Open the list and use the export action.')
        ->and($reply->metadata)->not->toHaveKey('refused')
        ->and(Message::query()->where('conversation_id', $this->conversation->id)->where('role', 'assistant')->count())->toBe(1);
});
