<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Lab404\Impersonate\Services\ImpersonateManager;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Jobs\LogRagQueryJob;
use Modules\AI\Models\Conversation;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Assistance\InAppAssistanceService;
use Modules\AI\Services\Documentation\Analytics\RagQueryAnalyticsWriter;
use Modules\AI\Services\Documentation\Analytics\RagQueryRecorder;
use Modules\AI\Services\Documentation\Analytics\RagQueryUserReference;
use Modules\AI\Tests\Stubs\Assistance\ScriptedAssistantFixtures;
use Modules\Core\Models\User;
use NeuronAI\RAG\Document;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @param  list<Document>  $documents
 */
function rag_logging_service(Request $request, array $documents): InAppAssistanceService
{
    return ScriptedAssistantFixtures::inAppService(
        $request,
        static fn (): string => 'Open Settings and select Profile.',
        static fn (): array => $documents,
    );
}

function rag_logging_document(): Document
{
    return (new Document('Open Settings, then Profile, to change your password.'))
        ->setSourceName('Application settings')
        ->setMetadata(['heading_breadcrumb' => 'Settings > Profile', 'locale' => 'en']);
}

function rag_logging_access(string $userId): AssistantAccessContext
{
    return new AssistantAccessContext(AssistantProfile::InAppAssistance, $userId, AssistantTenantScope::Global, null, 'en', [], 'conversation-1');
}

beforeEach(function (): void {
    config()->set('ai.features.faq.query_logging.enabled', true);
    config()->set('ai.features.faq.query_logging.query_text_mode', 'raw');
    config()->set('ai.features.faq.elasticsearch.user_index', 'laraplate_rag_user_docs_test');

    $this->user = User::factory()->create();
    $this->conversation = Conversation::query()->create(['user_id' => $this->user->id, 'system_message' => null]);
    $this->request = Request::create('/app/ai/assistance', 'POST');
    $this->request->setUserResolver(fn (): User => $this->user);
});

it('queues one log document for an answered question, with no user id in it', function (): void {
    Queue::fake([LogRagQueryJob::class]);

    rag_logging_service($this->request, [rag_logging_document()])
        ->respond($this->conversation, $this->user, 'How do I update my profile?');

    Queue::assertPushed(LogRagQueryJob::class, function (LogRagQueryJob $job): bool {
        return $job->document['user_ref'] === (new RagQueryUserReference)->for((string) $this->user->id)
            && $job->document['query'] === 'How do I update my profile?'
            && $job->document['retrieved_count'] === 1
            && $job->document['citation_count'] === 1
            && $job->document['answered'] === true
            && $job->document['index'] === 'laraplate_rag_user_docs_test'
            && is_float($job->document['latency_ms']);
    });
});

it('records an abstention when retrieval found nothing', function (): void {
    Queue::fake([LogRagQueryJob::class]);

    rag_logging_service($this->request, [])
        ->respond($this->conversation, $this->user, 'Something the documentation does not cover');

    Queue::assertPushed(LogRagQueryJob::class, static fn (LogRagQueryJob $job): bool => $job->document['answered'] === false
        && $job->document['citation_count'] === 0);
});

it('queues nothing while the log is switched off', function (): void {
    config()->set('ai.features.faq.query_logging.enabled', false);
    Queue::fake([LogRagQueryJob::class]);

    rag_logging_service($this->request, [rag_logging_document()])
        ->respond($this->conversation, $this->user, 'How do I update my profile?');

    Queue::assertNotPushed(LogRagQueryJob::class);
});

it('queues nothing for an impersonated session', function (): void {
    $this->mock(ImpersonateManager::class, function ($mock): void {
        $mock->shouldReceive('isImpersonating')->andReturnTrue();
    });
    Queue::fake([LogRagQueryJob::class]);

    rag_logging_service($this->request, [rag_logging_document()])
        ->respond($this->conversation, $this->user, 'How do I update my profile?');

    Queue::assertNotPushed(LogRagQueryJob::class);
});

it('queues nothing for a guest, who never reaches the assistant either', function (): void {
    $guest = User::factory()->create(['name' => config('permission.users.guest'), 'username' => config('permission.users.guest')]);
    Queue::fake([LogRagQueryJob::class]);

    app(RagQueryRecorder::class)->record($guest, rag_logging_access((string) $guest->id), 'How do I update my profile?', 1, 1, 5.0);

    Queue::assertNotPushed(LogRagQueryJob::class);
});

it('still answers when the log cannot be written', function (): void {
    $writer = Mockery::mock(RagQueryAnalyticsWriter::class);
    $writer->shouldReceive('write')->andThrow(new RuntimeException('cluster down'));
    app()->instance(RagQueryAnalyticsWriter::class, $writer);

    $reply = rag_logging_service($this->request, [rag_logging_document()])
        ->respond($this->conversation, $this->user, 'How do I update my profile?');

    expect($reply->content)->toBe('Open Settings and select Profile.')
        ->and($reply->metadata['refused'] ?? false)->toBeFalse();
});
