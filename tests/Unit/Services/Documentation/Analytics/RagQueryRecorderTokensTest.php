<?php

declare(strict_types=1);

use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Ai\Providers\AiRole;
use Modules\AI\Ai\Providers\RoleTokenUsage;
use Modules\AI\Ai\Providers\TokenUsage;
use Modules\AI\Ai\Rag\Judge\JudgeRound;
use Modules\AI\Ai\Rag\Judge\JudgeRun;
use Modules\AI\Ai\Rag\Judge\RelevanceJudge;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Jobs\LogRagQueryJob;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Documentation\Analytics\RagQueryAnalyticsWriter;
use Modules\AI\Services\Documentation\Analytics\RagQueryLog;
use Modules\AI\Services\Documentation\Analytics\RagQueryLogIndex;
use Modules\AI\Services\Documentation\Analytics\RagQueryRecorder;
use Modules\Core\Models\User;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Usage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\Testing\FakeAIProvider;

function tokens_access(): AssistantAccessContext
{
    return new AssistantAccessContext(AssistantProfile::InAppAssistance, '42', AssistantTenantScope::Global, null, 'en', [], 'conversation-1');
}

/**
 * The document the recorder queued for one answered question.
 *
 * @return array<string, string|int|float|bool>
 */
function tokens_recorded_document(?RoleTokenUsage $tokens): array
{
    Queue::fake([LogRagQueryJob::class]);

    app(RagQueryRecorder::class)->record(User::factory()->make(), tokens_access(), 'How do I reset a password?', 3, 1, 5.0, null, $tokens);

    $pushed = Queue::pushed(LogRagQueryJob::class);
    expect($pushed)->toHaveCount(1);

    return $pushed->first()->document;
}

beforeEach(function (): void {
    config()->set('app.key', 'base64:current-test-key');
    config()->set('ai.features.faq.query_logging.enabled', true);
    config()->set('ai.features.faq.query_logging.query_text_mode', 'raw');
    config()->set('ai.features.faq.query_logging.index', 'laraplate_rag_queries_test');
});

it('records usage per role when the provider reports it', function (): void {
    $document = tokens_recorded_document((new RoleTokenUsage)
        ->with(AiRole::ANSWER, new TokenUsage(120, 30))
        ->with(AiRole::JUDGE, new TokenUsage(50, 5)));

    expect($document)->toMatchArray([
        'tokens_in_answer' => 120,
        'tokens_out_answer' => 30,
        'tokens_in_judge' => 50,
        'tokens_out_judge' => 5,
    ])
        ->and(array_diff(array_keys($document), array_keys(RagQueryLogIndex::mappings()['properties'])))->toBe([]);
});

it('records no token field when the provider reports no usage', function (): void {
    $document = tokens_recorded_document((new RoleTokenUsage)->with(AiRole::ANSWER, null)->with(AiRole::JUDGE, null));
    $token_fields = array_filter(array_keys($document), static fn (string $field): bool => str_starts_with($field, 'tokens_'));

    expect($token_fields)->toBe([])
        ->and(tokens_recorded_document(null))->not->toHaveKey('tokens_in_answer');
});

it('declares the token fields of every role in the strict index mapping', function (): void {
    $properties = RagQueryLogIndex::mappings()['properties'];

    foreach (AiRole::ALL as $role) {
        expect($properties["tokens_in_{$role}"] ?? null)->toBe(['type' => 'integer'])
            ->and($properties["tokens_out_{$role}"] ?? null)->toBe(['type' => 'integer']);
    }
});

it('sums the usage of the model calls an agent made, and has none when the provider reported none', function (): void {
    $reporting = ChatAgent::make()->setAiProvider(new FakeAIProvider((new AssistantMessage('First.'))->setUsage(new Usage(11, 4))));
    $reporting->chat(new UserMessage('Hello'));
    $silent = ChatAgent::make()->setAiProvider(new FakeAIProvider(new AssistantMessage('First.')));
    $silent->chat(new UserMessage('Hello'));

    expect(TokenUsage::fromAgent($reporting))->toEqual(new TokenUsage(11, 4))
        ->and(TokenUsage::fromAgent($silent))->toBeNull();
});

it('carries the usage of each judge call into the judge run', function (): void {
    $provider = new FakeAIProvider((new AssistantMessage('{"relevant":[1],"next_query":null}'))->setUsage(new Usage(70, 9)));
    $judge = new RelevanceJudge(static fn (string $system): ChatAgent => ChatAgent::make(systemPrompt: $system)->setAiProvider($provider));

    $verdict = $judge->judge('question', [(new Document('Text.'))->setSourceName('A')], 1);
    $run = new JudgeRun([], applied: true, rounds: [
        new JudgeRound(1, 'question', 1, [1], 10.0, $verdict->usage),
        new JudgeRound(2, 'next', 1, [], 10.0, new TokenUsage(30, 1)),
        new JudgeRound(3, 'last', 1, [], 10.0),
    ]);

    expect($verdict->usage)->toEqual(new TokenUsage(70, 9))
        ->and($run->tokens())->toEqual(new TokenUsage(100, 10))
        ->and((new JudgeRun([], applied: true, rounds: [new JudgeRound(1, 'q', 1, [], 1.0)]))->tokens())->toBeNull();
});

it('stores the document without the token fields when an index made before them refuses them', function (): void {
    $document = RagQueryLog::fromAnswer(tokens_access(), 'question', 1, 1, 5.0, null, (new RoleTokenUsage)->with(AiRole::ANSWER, new TokenUsage(12, 3)))->toDocument();
    $without_tokens = array_diff_key($document, ['tokens_in_answer' => true, 'tokens_out_answer' => true]);
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('index')->once()->with(Mockery::on(static fn (array $params): bool => $params['body'] === $document))
        ->andThrow(new RuntimeException('400 Bad Request: {"error":{"type":"strict_dynamic_mapping_exception","reason":"mapping set to strict"}}'));
    $client->shouldReceive('index')->once()->with(Mockery::on(static fn (array $params): bool => $params['body'] === $without_tokens))
        ->andReturn(make_elasticsearch_response(['result' => 'created'], 201));
    Log::spy();

    (new RagQueryAnalyticsWriter($client))->write($document);

    Log::shouldNotHaveReceived('warning');
});
