<?php

declare(strict_types=1);

use Elastic\Elasticsearch\Client;
use Illuminate\Support\Facades\Log;
use Modules\AI\Ai\Rag\Judge\JudgeRound;
use Modules\AI\Ai\Rag\Judge\JudgeRun;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Documentation\Analytics\RagQueryAnalyticsWriter;
use Modules\AI\Services\Documentation\Analytics\RagQueryLog;
use Modules\AI\Services\Documentation\Analytics\RagQueryLogIndex;
use Modules\AI\Services\Documentation\Analytics\RagQueryUserReference;

function rag_query_access(string $userId = '42', ?string $tenantId = null): AssistantAccessContext
{
    return new AssistantAccessContext(
        AssistantProfile::InAppAssistance,
        $userId,
        $tenantId === null ? AssistantTenantScope::Global : AssistantTenantScope::Tenant,
        $tenantId,
        'it',
        [],
        'conversation-1',
    );
}

beforeEach(function (): void {
    config()->set('app.key', 'base64:current-test-key');
    config()->set('app.previous_keys', ['base64:previous-test-key']);
    config()->set('ai.features.faq.query_logging.index', 'laraplate_rag_queries_test');
    config()->set('ai.features.faq.elasticsearch.user_index', 'laraplate_rag_user_docs_test');
    config()->set('ai.features.faq.query_logging.query_text_mode', 'raw');
});

it('references the user by an HMAC under APP_KEY, never by the id', function (): void {
    $log = RagQueryLog::fromAnswer(rag_query_access('42'), 'How do I reset a password?', 3, 1, 12.5);
    $document = $log->toDocument();

    expect($document['user_ref'])->toBe(hash_hmac('sha256', '42', 'base64:current-test-key'))
        ->and($document['user_ref'])->not->toBe('42')
        ->and(json_encode($document))->not->toContain('"42"');
});

it('matches a user under the current and every previous key, for erasure', function (): void {
    expect((new RagQueryUserReference)->candidatesFor('42'))->toBe([
        hash_hmac('sha256', '42', 'base64:current-test-key'),
        hash_hmac('sha256', '42', 'base64:previous-test-key'),
    ]);
});

it('stores the question as typed in raw mode', function (): void {
    $document = RagQueryLog::fromAnswer(rag_query_access(), 'How do I reset a password?', 3, 1, 12.5)->toDocument();

    expect($document['query'])->toBe('How do I reset a password?');
});

it('stores no question at all in off mode', function (): void {
    config()->set('ai.features.faq.query_logging.query_text_mode', 'off');

    $document = RagQueryLog::fromAnswer(rag_query_access(), 'How do I reset a password?', 3, 1, 12.5)->toDocument();

    expect($document)->not->toHaveKey('query')
        ->and(json_encode($document))->not->toContain('reset a password');
});

it('stores no question when the mode is not one the review approved', function (): void {
    config()->set('ai.features.faq.query_logging.query_text_mode', 'hashed');

    expect(RagQueryLog::fromAnswer(rag_query_access(), 'How do I reset a password?', 3, 1, 12.5)->toDocument())
        ->not->toHaveKey('query');
});

it('builds a document with exactly the fields of the index mapping', function (): void {
    $document = RagQueryLog::fromAnswer(rag_query_access('42', 'tenant-7'), 'question', 4, 2, 31.25)->toDocument();
    $unjudged_fields = array_values(array_filter(
        array_keys(RagQueryLogIndex::mappings()['properties']),
        static fn (string $field): bool => ! str_starts_with($field, 'judge_'),
    ));

    expect(array_keys($document))->toEqualCanonicalizing($unjudged_fields)
        ->and($document)->toMatchArray([
            'tenant' => 'tenant-7',
            'profile' => 'in_app_assistance',
            'locale' => 'it',
            'retrieved_count' => 4,
            'citation_count' => 2,
            'answered' => true,
            'latency_ms' => 31.25,
            'index' => 'laraplate_rag_user_docs_test',
        ]);
});

it('records an abstention when no citation grounded the answer', function (): void {
    $document = RagQueryLog::fromAnswer(rag_query_access(), 'question', 5, 0, 10.0)->toDocument();

    expect($document['answered'])->toBeFalse()
        ->and($document['tenant'])->toBe('global');
});

it('indexes the document in the query log index', function (): void {
    $document = RagQueryLog::fromAnswer(rag_query_access(), 'question', 1, 1, 5.0)->toDocument();
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('index')->once()->with([
        'index' => 'laraplate_rag_queries_test',
        'id' => $document['id'],
        'body' => $document,
    ])->andReturn(make_elasticsearch_response(['result' => 'created'], 201));

    (new RagQueryAnalyticsWriter($client))->write($document);
});

it('swallows an Elasticsearch failure and logs it without the question', function (): void {
    $document = RagQueryLog::fromAnswer(rag_query_access(), 'a private question', 1, 1, 5.0)->toDocument();
    $client = Mockery::mock(Client::class);
    $client->shouldReceive('index')->andThrow(new RuntimeException('index down: a private question'));
    Log::spy();

    (new RagQueryAnalyticsWriter($client))->write($document);

    Log::shouldHaveReceived('warning')->once()->withArgs(
        static fn (string $message, array $context): bool => $message === 'rag_query_analytics_write_failed'
            && ! str_contains((string) json_encode($context), 'private question'),
    );
});

it('builds a judged document with every field of the index mapping', function (): void {
    $run = new JudgeRun([], applied: true, rounds: [new JudgeRound(1, 'question', 3, [2], 40.5)]);

    $document = RagQueryLog::fromAnswer(rag_query_access(), 'question', 3, 1, 12.5, $run)->toDocument();

    expect(array_keys($document))->toEqualCanonicalizing(array_keys(RagQueryLogIndex::mappings()['properties']))
        ->and($document['judge_trace'])->toBe('round=1 candidates=3 kept=2 ms=40.5');
});

it('stores no judge query in off mode', function (): void {
    config()->set('ai.features.faq.query_logging.query_text_mode', 'off');
    $run = new JudgeRun([], applied: true, rounds: [new JudgeRound(1, 'How do I reset a password?', 3, [], 40.5)]);

    $document = RagQueryLog::fromAnswer(rag_query_access(), 'How do I reset a password?', 3, 0, 12.5, $run)->toDocument();

    expect($document)->not->toHaveKey('judge_queries')
        ->and($document['judge_trace'])->toBe('round=1 candidates=3 kept=- ms=40.5')
        ->and(json_encode($document))->not->toContain('reset a password');
});
