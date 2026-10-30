<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\ElasticsearchRagVectorStore;
use Modules\AI\Ai\Rag\RagIndexRebuilder;
use Modules\AI\Exceptions\UnknownDocumentAudienceException;
use Modules\AI\Services\DocumentationService;

const RAG_ACTIVE = 'openai_compatible:intfloat/multilingual-e5-small';
const RAG_WIDE = 'openai_compatible:wide-768';

beforeEach(function (): void {
    config()->set('ai.features.embeddings.models.' . RAG_WIDE, ['dimensions' => 768]);
    config()->set('ai.features.embeddings.serving_model', RAG_ACTIVE);
    config()->set('ai.features.faq.enabled', true);
    config()->set('ai.features.faq.vector_store', 'elasticsearch');
});

it('sizes the RAG vectors from the active embedding profile', function (): void {
    expect(ElasticsearchRagVectorStore::activeDimensions())->toBe(384)
        ->and(app(EmbeddingModelRegistry::class)->withActive(RAG_WIDE, static fn (): int => ElasticsearchRagVectorStore::activeDimensions()))->toBe(768)
        ->and(config()->has('ai.features.faq.elasticsearch.embedding_dims'))->toBeFalse();
});

it('recreates the RAG indexes with the target profile in force and indexes no document', function (): void {
    $seen = [];
    Artisan::shouldReceive('call')
        ->once()
        ->andReturnUsing(static function (string $command, array $parameters) use (&$seen): int {
            $seen[] = [$command, $parameters, ElasticsearchRagVectorStore::activeDimensions()];

            return 0;
        });

    app(RagIndexRebuilder::class)->prepare(app(EmbeddingModelRegistry::class)->get(RAG_WIDE));

    expect($seen)->toBe([
        ['ai:create-rag-index', ['--profile' => 'all', '--force' => true], 768],
    ])
        ->and(ElasticsearchRagVectorStore::activeDimensions())->toBe(384);
});

it('fails with the command output when recreating the RAG indexes fails', function (): void {
    Artisan::shouldReceive('call')->once()->andReturn(1);
    Artisan::shouldReceive('output')->once()->andReturn('cluster unreachable');

    expect(fn () => app(RagIndexRebuilder::class)->prepare(app(EmbeddingModelRegistry::class)->get(RAG_WIDE)))
        ->toThrow(RuntimeException::class, 'cluster unreachable');
});

it('plans per documentation profile ranges of source names, open at both ends', function (): void {
    $documentation = Mockery::mock(DocumentationService::class);
    $documentation->shouldReceive('sourceNames')->with(DocumentationIndexProfile::Developer)->andReturn(['a.md', 'b.md', 'c.md', 'd.md', 'e.md']);
    $documentation->shouldReceive('sourceNames')->with(DocumentationIndexProfile::User)->andReturn([]);
    app()->instance(DocumentationService::class, $documentation);

    expect(app(RagIndexRebuilder::class)->plan(2))->toBe([
        'rag:developer#0' => ['model' => 'rag:developer', 'from' => null, 'to' => 'c.md'],
        'rag:developer#1' => ['model' => 'rag:developer', 'from' => 'c.md', 'to' => 'e.md'],
        'rag:developer#2' => ['model' => 'rag:developer', 'from' => 'e.md', 'to' => null],
        'rag:user#0' => ['model' => 'rag:user', 'from' => null, 'to' => null],
    ]);
});

it('writes a documentation chunk by indexing its source range with the target profile in force', function (): void {
    $seen = [];
    $documentation = Mockery::mock(DocumentationService::class);
    $documentation->shouldReceive('indexSources')
        ->once()
        ->andReturnUsing(static function (DocumentationIndexProfile $profile, ?string $from, ?string $to) use (&$seen): int {
            $seen[] = [$profile, $from, $to, ElasticsearchRagVectorStore::activeDimensions()];

            return 4;
        });
    app()->instance(DocumentationService::class, $documentation);

    app(RagIndexRebuilder::class)->writeChunk(app(EmbeddingModelRegistry::class)->get(RAG_WIDE), ['model' => 'rag:user', 'from' => 'c.md', 'to' => null]);

    expect($seen)->toBe([[DocumentationIndexProfile::User, 'c.md', null, 768]])
        ->and(ElasticsearchRagVectorStore::activeDimensions())->toBe(384)
        ->and(fn () => app(RagIndexRebuilder::class)->writeChunk(app(EmbeddingModelRegistry::class)->get(RAG_WIDE), ['model' => 'rag:nobody', 'from' => null, 'to' => null]))
        ->toThrow(InvalidArgumentException::class);
});

it('does nothing and plans nothing when the documentation is not kept in Elasticsearch', function (string $key, mixed $value): void {
    config()->set($key, $value);
    Artisan::shouldReceive('call')->never();
    $documentation = Mockery::mock(DocumentationService::class);
    $documentation->shouldNotReceive('sourceNames');
    app()->instance(DocumentationService::class, $documentation);

    app(RagIndexRebuilder::class)->prepare(app(EmbeddingModelRegistry::class)->get(RAG_WIDE));

    expect(app(RagIndexRebuilder::class)->plan(20))->toBe([]);
})->with([
    'faq disabled' => ['ai.features.faq.enabled', false],
    'filesystem store' => ['ai.features.faq.vector_store', 'filesystem'],
]);

it('validates the documentation sources before recreating the RAG indexes', function (): void {
    Artisan::shouldReceive('call')->never();
    $documentation = Mockery::mock(DocumentationService::class);
    $documentation->shouldReceive('validateSources')
        ->once()
        ->andThrow(new UnknownDocumentAudienceException('/docs/rag/WRONG.md', 'admin'));
    app()->instance(DocumentationService::class, $documentation);

    expect(fn () => app(RagIndexRebuilder::class)->prepare(app(EmbeddingModelRegistry::class)->get(RAG_WIDE)))
        ->toThrow(UnknownDocumentAudienceException::class, '/docs/rag/WRONG.md');
});
