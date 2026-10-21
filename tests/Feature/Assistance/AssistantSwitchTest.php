<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\WriteProposal;
use Modules\AI\Services\Assistance\AssistantCapabilities;
use Modules\AI\Tests\Stubs\Assistance\ScriptedAssistantFixtures;
use Modules\Core\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->conversation = Conversation::query()->create(['user_id' => $this->user->id, 'system_message' => null]);
    config([
        'ai.features.faq.enabled' => true,
        'ai.features.chat.model' => 'ollama:llama3.2:3b',
        'ai.providers.ollama.api_url' => 'http://localhost:11434',
    ]);
});

/**
 * A request on each route that serves or decides on the assistant, as the signed-in user.
 */
function assistantRequest(object $test, string $name, Conversation $conversation): Illuminate\Testing\TestResponse
{
    return match ($name) {
        'list conversations' => $test->getJson(route('ai.crud.conversations.list')),
        'create a conversation' => $test->postJson(route('ai.crud.conversations.insert')),
        'conversation detail' => $test->getJson(route('ai.crud.conversations.detail', $conversation)),
        'delete a conversation' => $test->deleteJson(route('ai.crud.conversations.delete', $conversation)),
        'list messages' => $test->getJson(route('ai.crud.messages.list', $conversation)),
        'send a message' => $test->postJson(route('ai.crud.messages.insert', $conversation), ['message' => 'Hello']),
        'stream a message' => $test->postJson(route('ai.crud.messages.stream', $conversation), ['message' => 'Hello']),
        'run the agent' => $test->postJson(route('ai.agent'), ['threadId' => $conversation->id, 'message' => 'Hello']),
    };
}

it('is off until the assistant setting is on, whatever the documentation setting says', function (): void {
    config(['ai.features.chat.enabled' => null]);
    expect(app(AssistantCapabilities::class)->enabled())->toBeFalse();

    config(['ai.features.chat.enabled' => false, 'ai.features.faq.enabled' => true]);
    expect(app(AssistantCapabilities::class)->enabled())->toBeFalse();

    config(['ai.features.chat.enabled' => true, 'ai.features.faq.enabled' => false]);
    expect(app(AssistantCapabilities::class)->enabled())->toBeTrue();
});

it('says whether the assistant can answer from the documentation, apart from being on', function (): void {
    config(['ai.features.chat.enabled' => true, 'ai.features.faq.enabled' => false]);

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.features.help', false);

    config(['ai.features.faq.enabled' => true]);

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertJsonPath('data.features.help', true);
});

it('refuses every assistant route with FEATURE_DISABLED while the assistant is off', function (string $name): void {
    config(['ai.features.chat.enabled' => false]);
    $this->actingAs($this->user);

    assistantRequest($this, $name, $this->conversation)
        ->assertForbidden()
        ->assertJsonPath('code', 'FEATURE_DISABLED');

    expect($this->conversation->fresh())->not->toBeNull()
        ->and($this->conversation->messages()->count())->toBe(0);
})->with([
    'list conversations',
    'create a conversation',
    'conversation detail',
    'delete a conversation',
    'list messages',
    'send a message',
    'stream a message',
    'run the agent',
]);

it('refuses the decision on a proposed write while the assistant is off, and leaves it waiting', function (): void {
    config(['ai.features.chat.enabled' => false]);
    $proposal = WriteProposal::factory()->create([
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->conversation->getKey(),
    ]);
    $status = $proposal->status;

    $this->actingAs($this->user)
        ->postJson(route('ai.crud.assistant-writes.confirm', $proposal))
        ->assertForbidden()
        ->assertJsonPath('code', 'FEATURE_DISABLED');

    $this->actingAs($this->user)
        ->postJson(route('ai.crud.assistant-writes.reject', $proposal))
        ->assertForbidden()
        ->assertJsonPath('code', 'FEATURE_DISABLED');

    expect($proposal->fresh()->status)->toBe($status);
});

it('serves the conversation routes once the assistant is on', function (): void {
    config(['ai.features.chat.enabled' => true]);

    $this->actingAs($this->user)->getJson(route('ai.crud.conversations.list'))->assertOk();
    $this->actingAs($this->user)->getJson(route('ai.crud.messages.list', $this->conversation))->assertOk();
});

it('does not tell a request that is not signed in that the assistant is off', function (): void {
    config(['ai.features.chat.enabled' => false]);

    $this->postJson(route('ai.agent'), ['threadId' => $this->conversation->id, 'message' => 'Hello'])->assertUnauthorized();
    $this->getJson(route('ai.crud.conversations.list'))->assertJsonMissing(['code' => 'FEATURE_DISABLED']);
});

it('still answers capabilities while the assistant is off, so a client can hide its surfaces', function (): void {
    config(['ai.features.chat.enabled' => false]);

    $this->actingAs($this->user)
        ->getJson(route('ai.capabilities'))
        ->assertOk()
        ->assertJsonPath('data.enabled', false);
});

it('answers without reading the documentation when its setting is off', function (): void {
    config(['ai.features.chat.enabled' => true, 'ai.features.faq.enabled' => false]);
    $request = Request::create('/app/ai/assistance', 'POST');
    $request->setUserResolver(fn (): User => $this->user);
    $retrieved = false;
    $prompt = null;

    $service = ScriptedAssistantFixtures::inAppService(
        $request,
        function (string $input, string $system_prompt) use (&$prompt): string {
            $prompt = $system_prompt;

            return 'Here is what I can see.';
        },
        function () use (&$retrieved): array {
            $retrieved = true;

            return [];
        },
    );

    $service->respond($this->conversation, $this->user, 'How many orders are open?');

    expect($retrieved)->toBeFalse()
        ->and($prompt)->not->toContain('user-assistance documentation corpus');
});

it('reads the documentation when its setting is on', function (): void {
    config(['ai.features.chat.enabled' => true, 'ai.features.faq.enabled' => true]);
    $request = Request::create('/app/ai/assistance', 'POST');
    $request->setUserResolver(fn (): User => $this->user);
    $retrieved = false;
    $prompt = null;

    $service = ScriptedAssistantFixtures::inAppService(
        $request,
        function (string $input, string $system_prompt) use (&$prompt): string {
            $prompt = $system_prompt;

            return 'Open Settings.';
        },
        function () use (&$retrieved): array {
            $retrieved = true;

            return [];
        },
    );

    $service->respond($this->conversation, $this->user, 'How do I create an order?');

    expect($retrieved)->toBeTrue()
        ->and($prompt)->toContain('user-assistance documentation corpus');
});
