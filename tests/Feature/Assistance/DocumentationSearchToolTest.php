<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\Assistance\InAppAssistanceService;
use Modules\AI\Services\ChatService;
use Modules\AI\Tests\Stubs\Assistance\ScriptedAssistantFixtures;
use Modules\AI\Tests\Stubs\Assistance\ToolCallingFakeProvider;
use Modules\Core\Models\User;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\RAG\Document;
use NeuronAI\Testing\FakeAIProvider;
use NeuronAI\Tools\Tool;

uses(RefreshDatabase::class);

function documentationSearchDocument(string $label, string $content): Document
{
    return (new Document($content))->setSourceName($label)->setMetadata(['locale' => 'en']);
}

/**
 * @param  Closure(string): list<Document>  $retrieve
 */
function documentationSearchService(Request $request, FakeAIProvider $model, Closure $retrieve): InAppAssistanceService
{
    $real = app(ChatService::class);
    $chat = Mockery::mock(ChatService::class)->makePartial();
    $chat->shouldReceive('buildProtectedAgent')->andReturnUsing(
        fn (...$arguments): ChatAgent => $real->buildProtectedAgent(...$arguments)->setAiProvider($model),
    );

    return ScriptedAssistantFixtures::inAppService($request, retrieve: static fn (string $query): array => $retrieve($query), chat: $chat);
}

beforeEach(function (): void {
    config()->set('ai.features.faq.enabled', true);
    $this->user = User::factory()->create();
    $this->conversation = Conversation::query()->create(['user_id' => $this->user->id, 'system_message' => null]);
    $this->request = Request::create('/app/ai/assistance', 'POST');
    $this->request->setUserResolver(fn (): User => $this->user);
});

it('offers no documentation search unless it is switched on', function (): void {
    config()->set('ai.features.rag.retrieval_tool.enabled', false);
    $model = new FakeAIProvider(new AssistantMessage('Open Settings.'));

    documentationSearchService($this->request, $model, static fn (string $query): array => [])
        ->respond($this->conversation, $this->user, 'How do I change the layout?');

    $tools = array_map(static fn (Tool $tool): string => $tool->getName(), $model->getRecorded()[0]->tools);

    expect($tools)->not->toContain(InAppAssistanceService::DOCUMENTATION_SEARCH_TOOL);
});

it('lets the model search the documentation itself and cites what it read after what was retrieved first', function (): void {
    config()->set('ai.features.rag.retrieval_tool.enabled', true);
    $asked = [];
    $model = new ToolCallingFakeProvider(
        [[InAppAssistanceService::DOCUMENTATION_SEARCH_TOOL, ['query' => 'layout presets']]],
        new AssistantMessage('Pick a preset in Settings, Layout.'),
    );

    $reply = documentationSearchService($this->request, $model, static function (string $query) use (&$asked): array {
        $asked[] = $query;

        return $query === 'layout presets'
            ? [documentationSearchDocument('Layout presets', 'Settings, Layout lists the presets.')]
            : [documentationSearchDocument('Settings guide', 'Open Settings to change how the application looks.')];
    })->respond($this->conversation, $this->user, 'How do I change the layout?');

    $labels = array_map(static fn (array $citation): string => $citation['label'], $reply->metadata['citations']);

    expect($asked)->toBe(['How do I change the layout?', 'layout presets'])
        ->and($reply->content)->toBe('Pick a preset in Settings, Layout.')
        ->and($labels)->toBe(['Settings guide', 'Layout presets']);
});

it('sends the authorized context with the question and keeps it out of the cached instructions', function (): void {
    config()->set('ai.features.rag.retrieval_tool.enabled', false);
    $model = new FakeAIProvider(new AssistantMessage('Open Settings.'));

    documentationSearchService($this->request, $model, static fn (string $query): array => [
        documentationSearchDocument('Settings guide', 'Open Settings to change how the application looks.'),
    ])->respond($this->conversation, $this->user, 'How do I change the layout?');

    $request = $model->getRecorded()[0];
    $messages = $request->messages;
    $question = end($messages);

    expect($request->systemPrompt?->getContent())->not->toContain('Open Settings to change how the application looks.')
        ->and($request->systemPrompt?->isCached())->toBeTrue()
        ->and($question->getContent())->toContain('How do I change the layout?')
        ->and($question->getContent())->toContain('<authorized_context>')
        ->and($question->getContent())->toContain('Open Settings to change how the application looks.');
});
