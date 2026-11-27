<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Enums\AssistantTurnStatus;
use Modules\AI\Enums\WriteProposalStatus;
use Modules\AI\Models\AgentMessage;
use Modules\AI\Models\AgentWorkflowRecord;
use Modules\AI\Models\AssistantTurn;
use Modules\AI\Models\Conversation;
use Modules\AI\Models\WriteProposal;
use Modules\AI\Services\Assistance\AssistantPromptContext;
use Modules\AI\Services\Assistance\Contracts\InAppAssistanceServiceInterface;
use Modules\AI\Services\Assistance\InAppAssistanceService;
use Modules\AI\Services\Assistance\Policies\CompiledAssistantPolicy;
use Modules\AI\Services\Assistance\Writes\WriteProposalService;
use Modules\AI\Services\ChatService;
use Modules\AI\Services\Tools\CrudToolProvider;
use Modules\AI\Tests\Stubs\Assistance\ScriptedAssistantFixtures;
use Modules\AI\Tests\Stubs\Assistance\ToolCallingFakeProvider;
use Modules\Core\Models\DynamicEntity;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Services\Authorization\AuthorizationService;
use Modules\Core\Services\Crud\CrudService;
use Modules\Core\Services\Export\TabularCsvExporter;
use Modules\Core\Services\Export\TabularPdfExporter;
use Modules\Core\Support\PermissionName;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Testing\FakeAIProvider;

uses(RefreshDatabase::class);

/**
 * The in-app service with the real pipeline and the CRUD tools of the role entity, whose model is `$model`: the
 * agent of the turn and the one rebuilt when the person decides both answer from it.
 */
function approvalFlowService(Request $request, CrudToolProvider $tools, FakeAIProvider $model): InAppAssistanceService
{
    $real = app(ChatService::class);
    $chat = Mockery::mock(ChatService::class)->makePartial();
    $chat->shouldReceive('buildProtectedAgent')->andReturnUsing(
        fn (...$arguments): ChatAgent => $real->buildProtectedAgent(...$arguments)->setAiProvider($model),
    );

    $service = ScriptedAssistantFixtures::inAppService($request, chat: $chat, tools: $tools);
    app()->instance(InAppAssistanceServiceInterface::class, $service);

    return $service;
}

beforeEach(function (): void {
    $this->user = user_class()::factory()->create();
    Auth::login($this->user);

    foreach (['select', 'update'] as $ability) {
        $name = PermissionName::forModel(DynamicEntity::resolve('role', module: 'core'), $ability);
        Permission::findOrCreate($name, 'web');
        $this->user->givePermissionTo($name);
    }

    Config::set('ai.features.tools.crud.entities', ['core.role' => ['list', 'update']]);
    Config::set('ai.features.tools.crud.unmoderated_writes', ['core.role']);
    $this->role = Role::factory()->create(['name' => 'before']);
    $this->conversation = Conversation::query()->create(['user_id' => $this->user->getKey()]);

    $this->request = Request::create('/app/ai/assistance', 'POST', ['message' => 'hello']);
    $this->request->setUserResolver(fn () => $this->user);
    app()->instance(Request::class, $this->request);
    $this->tools = new CrudToolProvider(
        resolve(CrudService::class),
        resolve(AuthorizationService::class),
        $this->request,
        resolve(TabularCsvExporter::class),
        resolve(TabularPdfExporter::class),
        resolve(WriteProposalService::class),
    );
});

it('pauses the run on a write, stores the proposal for the call and changes nothing', function (): void {
    $model = new ToolCallingFakeProvider(
        [['crud_update_core_role', ['id' => (string) $this->role->getKey(), 'attributes' => ['name' => 'after']]]],
        new AssistantMessage('The role is now called "after".'),
    );
    $service = approvalFlowService($this->request, $this->tools, $model);

    $reply = $service->respond($this->conversation, $this->user, 'Rename the role.');
    $turn = AssistantTurn::query()->sole();
    $proposal = WriteProposal::query()->sole();

    expect($this->role->fresh()->name)->toBe('before')
        ->and($reply->content)->toBe('I prepared a change for you to confirm. Nothing changes until you confirm it.')
        ->and($reply->metadata['writes'])->toHaveCount(1)
        ->and($turn->status)->toBe(AssistantTurnStatus::AwaitingDecisions)
        ->and($turn->reply_message_id)->toBe($reply->id)
        ->and($proposal->turn_id)->toBe($turn->id)
        ->and($proposal->tool_call_id)->toBe('call_1')
        ->and($proposal->status)->toBe(WriteProposalStatus::Proposed)
        ->and(AgentWorkflowRecord::query()->where('partition', AgentWorkflowRecord::partitionOf($turn->id))->exists())->toBeTrue()
        ->and(AgentMessage::query()->where('thread_id', $turn->id)->exists())->toBeTrue();

    $model->assertCallCount(1);
});

it('resumes the turn once the person confirms, applies the write once and writes the follow-up', function (): void {
    $model = new ToolCallingFakeProvider(
        [['crud_update_core_role', ['id' => (string) $this->role->getKey(), 'attributes' => ['name' => 'after']]]],
        new AssistantMessage('The role is now called "after".'),
    );
    approvalFlowService($this->request, $this->tools, $model)->respond($this->conversation, $this->user, 'Rename the role.');
    $proposal = WriteProposal::query()->sole();

    $response = $this->actingAs($this->user)
        ->postJson(route('ai.crud.assistant-writes.confirm', $proposal))
        ->assertOk();

    $turn = AssistantTurn::query()->sole();
    $follow_up = $this->conversation->messages()->latest('id')->first();

    expect($this->role->fresh()->name)->toBe('after')
        ->and($proposal->fresh()->status)->toBe(WriteProposalStatus::Applied)
        ->and($response->json('data.status'))->toBe('applied')
        ->and($response->json('data.follow_up.content'))->toBe('The role is now called "after".')
        ->and($follow_up->content)->toBe('The role is now called "after".')
        ->and($follow_up->metadata['follow_up_of'])->toBe($turn->reply_message_id)
        ->and($turn->status)->toBe(AssistantTurnStatus::Completed)
        ->and($turn->follow_up_message_id)->toBe($follow_up->id);

    $model->assertCallCount(2);

    $result = collect(new ChatService()->buildProtectedAgent(
        new CompiledAssistantPolicy('v', 'p', [], [], []),
        new AssistantPromptContext('v', [], [], []),
        'ollama',
        $turn->id,
    )->getChatHistory()->getMessages())->first(static fn (Message $message): bool => $message instanceof ToolResultMessage);

    expect($result)->toBeInstanceOf(ToolResultMessage::class)
        ->and((string) $result->getToolCalls()[0]->getResult())->toContain('"status":"applied"');
});

it('tells the model the person declined, so the follow-up says nothing was changed', function (): void {
    $model = new ToolCallingFakeProvider(
        [['crud_update_core_role', ['id' => (string) $this->role->getKey(), 'attributes' => ['name' => 'after']]]],
        new AssistantMessage('Understood, I left the role as it is.'),
    );
    approvalFlowService($this->request, $this->tools, $model)->respond($this->conversation, $this->user, 'Rename the role.');
    $proposal = WriteProposal::query()->sole();

    $response = $this->actingAs($this->user)
        ->postJson(route('ai.crud.assistant-writes.reject', $proposal))
        ->assertOk();

    expect($this->role->fresh()->name)->toBe('before')
        ->and($proposal->fresh()->status)->toBe(WriteProposalStatus::Rejected)
        ->and($response->json('data.follow_up.content'))->toBe('Understood, I left the role as it is.')
        ->and(AssistantTurn::query()->sole()->status)->toBe(AssistantTurnStatus::Completed);
});

it('answers an invalid write to the model at once, so the turn goes on without the person', function (): void {
    $model = new ToolCallingFakeProvider(
        [['crud_update_core_role', ['id' => (string) $this->role->getKey(), 'attributes' => []]]],
        new AssistantMessage('I need the new name of the role.'),
    );

    $reply = approvalFlowService($this->request, $this->tools, $model)->respond($this->conversation, $this->user, 'Rename the role.');
    $turn = AssistantTurn::query()->sole();

    expect($reply->content)->toBe('I need the new name of the role.')
        ->and($reply->metadata)->not->toHaveKey('writes')
        ->and(WriteProposal::query()->count())->toBe(0)
        ->and($turn->status)->toBe(AssistantTurnStatus::Completed)
        ->and($turn->call_answers['call_1']['error'])->toBe('At least one attribute to set is required.');

    $model->assertCallCount(2);
});

it('does not resume a turn for somebody else, nor one that waits for no decision', function (): void {
    $model = new ToolCallingFakeProvider(
        [['crud_update_core_role', ['id' => (string) $this->role->getKey(), 'attributes' => ['name' => 'after']]]],
        new AssistantMessage('Done.'),
    );
    $service = approvalFlowService($this->request, $this->tools, $model);
    $service->respond($this->conversation, $this->user, 'Rename the role.');
    $proposal = WriteProposal::query()->sole();

    expect($service->continueAfterDecision($proposal, user_class()::factory()->create()))->toBeNull()
        ->and($service->continueAfterDecision($proposal, $this->user))->toBeNull();

    $model->assertCallCount(1);
});

it('expires an open proposal past its time and lets the turn go on as declined', function (): void {
    $model = new ToolCallingFakeProvider(
        [['crud_update_core_role', ['id' => (string) $this->role->getKey(), 'attributes' => ['name' => 'after']]]],
        new AssistantMessage('The change expired, so nothing was done.'),
    );
    $service = approvalFlowService($this->request, $this->tools, $model);
    $service->respond($this->conversation, $this->user, 'Rename the role.');
    $proposal = WriteProposal::query()->sole();
    $proposal->update(['expires_at' => now()->subMinute()]);

    $follow_up = $service->continueAfterDecision($proposal->fresh(), $this->user);

    expect($follow_up?->content)->toBe('The change expired, so nothing was done.')
        ->and($proposal->fresh()->status)->toBe(WriteProposalStatus::Expired)
        ->and($this->role->fresh()->name)->toBe('before');
});

it('prunes an old turn with its Neuron records and keeps the proposal', function (): void {
    $model = new ToolCallingFakeProvider(
        [['crud_update_core_role', ['id' => (string) $this->role->getKey(), 'attributes' => ['name' => 'after']]]],
        new AssistantMessage('Done.'),
    );
    approvalFlowService($this->request, $this->tools, $model)->respond($this->conversation, $this->user, 'Rename the role.');
    $turn = AssistantTurn::query()->sole();
    AssistantTurn::query()->whereKey($turn->id)->update(['updated_at' => now()->subDays(AssistantTurn::RETENTION_DAYS + 1)]);

    $this->artisan('model:prune', ['--model' => [AssistantTurn::class]])->assertSuccessful();

    expect(AssistantTurn::query()->count())->toBe(0)
        ->and(AgentWorkflowRecord::query()->count())->toBe(0)
        ->and(AgentMessage::query()->count())->toBe(0)
        ->and(WriteProposal::query()->sole()->turn_id)->toBeNull();
});
