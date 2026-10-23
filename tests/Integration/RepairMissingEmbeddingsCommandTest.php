<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Console\RepairMissingEmbeddingsCommand;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Tests\Stubs\DraftAwareEmbeddableTestModel;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @param  array<string, mixed>  $args
 */
function run_repair_embeddings_command(array $args): CommandTester
{
    $command = app(RepairMissingEmbeddingsCommand::class);
    $command->setLaravel(app());

    $tester = new CommandTester($command);
    $tester->execute($args);

    return $tester;
}

/**
 * A /v1/embeddings answer carrying one vector, of the active profile's dimensions unless told otherwise.
 */
function repair_probe_answer(?int $dimensions = null): GuzzleHttp\Promise\PromiseInterface
{
    $dimensions ??= app(EmbeddingModelRegistry::class)->active()->dimensions;

    return Http::response(['data' => [['index' => 0, 'embedding' => ai_test_unit_vector($dimensions)]]]);
}

/**
 * Fakes the embeddings server: /info reports the active model and /v1/embeddings embeds the probe.
 */
function repair_fake_embedding_service(): void
{
    Http::fake([
        '*/info' => Http::response(['model_id' => app(EmbeddingModelRegistry::class)->active()->serviceModel]),
        '*/v1/embeddings' => repair_probe_answer(),
    ]);
}

beforeEach(function (): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    Config::set('core.search.vector.enabled', true);
    Config::set('ai.features.embeddings.enabled', true);

    Schema::create('embeddable_test_models', function ($table): void {
        $table->id();
        $table->string('title')->nullable();
    });
});

it('returns invalid, as the scout commands do, when the model class does not exist', function (): void {
    $tester = run_repair_embeddings_command(['model' => 'Nope\\Missing']);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::INVALID);
});

it('returns failure when vector search is disabled', function (): void {
    Config::set('core.search.vector.enabled', false);

    $tester = run_repair_embeddings_command(['model' => EmbeddableTestModel::class]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::FAILURE);
});

it('dispatches jobs only for records missing an embedding and carrying embeddable text', function (): void {
    Queue::fake();
    repair_fake_embedding_service();

    $alpha = new EmbeddableTestModel(['title' => 'Alpha']);
    $alpha->saveQuietly();

    $beta = new EmbeddableTestModel(['title' => 'Beta']);
    $beta->saveQuietly();

    // No embeddable text -> skipped.
    $empty = new EmbeddableTestModel(['title' => '']);
    $empty->saveQuietly();

    // Already embedded -> excluded by whereDoesntHave.
    $already = new EmbeddableTestModel(['title' => 'Delta']);
    $already->saveQuietly();
    $already->embeddings()->create(['embedding' => [0.1, 0.2]]);

    $tester = run_repair_embeddings_command(['model' => EmbeddableTestModel::class]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::SUCCESS);
    Queue::assertPushed(GenerateEmbeddingsJob::class, 2);
});

it('skips records the search index would not hold, as shouldBeSearchable() decides', function (): void {
    Queue::fake();
    repair_fake_embedding_service();

    (new DraftAwareEmbeddableTestModel(['title' => 'Published post']))->saveQuietly();
    (new DraftAwareEmbeddableTestModel(['title' => 'Draft of a post']))->saveQuietly();

    $tester = run_repair_embeddings_command(['model' => DraftAwareEmbeddableTestModel::class]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::SUCCESS);
    Queue::assertPushed(GenerateEmbeddingsJob::class, 1);
});

it('probes /v1/embeddings of the active profile server before it dispatches anything', function (): void {
    Queue::fake();
    Config::set('ai.servers.embeddings.api_key', 'secret-key');
    repair_fake_embedding_service();

    (new EmbeddableTestModel(['title' => 'Alpha']))->saveQuietly();

    run_repair_embeddings_command(['model' => EmbeddableTestModel::class]);

    $active = app(EmbeddingModelRegistry::class)->active();

    Http::assertSent(static fn (Illuminate\Http\Client\Request $request): bool => str_ends_with($request->url(), '/v1/embeddings')
        && $request['model'] === $active->serviceModel
        && $request['encoding_format'] === 'float'
        && is_array($request['input'])
        && $request->hasHeader('Authorization', 'Bearer secret-key'));
});

it('aborts before dispatching anything when the embedding service cannot embed', function (Closure $embed, string $reason): void {
    Queue::fake();
    Http::fake([
        '*/info' => Http::response(['model_id' => app(EmbeddingModelRegistry::class)->active()->serviceModel]),
        '*/v1/embeddings' => $embed(),
    ]);

    (new EmbeddableTestModel(['title' => 'Alpha']))->saveQuietly();

    $tester = run_repair_embeddings_command(['model' => EmbeddableTestModel::class]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::FAILURE)
        ->and($tester->getDisplay())->toContain('probe failed')
        ->and($tester->getDisplay())->toContain($reason);
    Queue::assertNothingPushed();
})->with([
    'a server error' => [fn (): GuzzleHttp\Promise\PromiseInterface => Http::response('Internal Server Error', 500), '500'],
    'an answer without embeddings' => [fn () => Http::response(['detail' => 'nope']), 'the answer carries no embedding'],
    'an empty list of embeddings' => [fn () => Http::response(['data' => []]), 'the answer carries no embedding'],
    'the wrong dimensions' => [fn () => Http::response(['data' => [['index' => 0, 'embedding' => [0.1, 0.2]]]]), 'it returned 2 dimensions, the active profile "openai_compatible:intfloat/multilingual-e5-small" expects 384'],
    'a service that is unreachable' => [fn (): Closure => static fn () => throw new ConnectionException('connection timed out'), 'connection timed out'],
]);

/**
 * @param  list<class-string<Illuminate\Database\Eloquent\Model>>  $models
 */
function repair_embeddable_models(array $models): void
{
    $resolver = Mockery::mock(IEmbeddableModels::class);
    $resolver->shouldReceive('all')->andReturn($models);
    app()->instance(IEmbeddableModels::class, $resolver);
}

it('repairs every embeddable model with --all, probing the service once', function (): void {
    Queue::fake();
    repair_fake_embedding_service();
    // Same table on purpose: the draft-aware stub skips the draft row, so its pass pushes one job less.
    repair_embeddable_models([EmbeddableTestModel::class, DraftAwareEmbeddableTestModel::class]);

    (new EmbeddableTestModel(['title' => 'Published post']))->saveQuietly();
    (new EmbeddableTestModel(['title' => 'Draft of a post']))->saveQuietly();

    $tester = run_repair_embeddings_command(['--all' => true]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain(EmbeddableTestModel::class)
        ->and($tester->getDisplay())->toContain(DraftAwareEmbeddableTestModel::class);
    Queue::assertPushed(GenerateEmbeddingsJob::class, 3);
    Http::assertSentCount(2);
});

it('has nothing to do with --all when no model is embeddable, and does not touch the service', function (): void {
    Queue::fake();
    repair_fake_embedding_service();
    repair_embeddable_models([]);

    $tester = run_repair_embeddings_command(['--all' => true]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('No embeddable model');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('refuses a model together with --all', function (): void {
    Queue::fake();

    $tester = run_repair_embeddings_command(['model' => EmbeddableTestModel::class, '--all' => true]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::INVALID);
    Queue::assertNothingPushed();
});

it('does nothing with --all while the embeddings feature is off', function (): void {
    Queue::fake();
    repair_fake_embedding_service();
    repair_embeddable_models([EmbeddableTestModel::class]);
    Config::set('ai.features.embeddings.enabled', false);

    (new EmbeddableTestModel(['title' => 'Alpha']))->saveQuietly();

    $tester = run_repair_embeddings_command(['--all' => true]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('disabled');
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('skips with --if-idle while embeddings jobs are still queued, so a backlog is not dispatched twice', function (): void {
    Queue::fake();
    repair_fake_embedding_service();

    $pending = new EmbeddableTestModel(['title' => 'Already queued']);
    $pending->saveQuietly();
    dispatch(new GenerateEmbeddingsJob($pending));

    (new EmbeddableTestModel(['title' => 'Alpha']))->saveQuietly();

    $tester = run_repair_embeddings_command(['model' => EmbeddableTestModel::class, '--if-idle' => true]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('still queued');
    Queue::assertPushed(GenerateEmbeddingsJob::class, 1);
    Http::assertNothingSent();
});

it('runs with --if-idle when no embeddings job is queued', function (): void {
    Queue::fake();
    repair_fake_embedding_service();

    (new EmbeddableTestModel(['title' => 'Alpha']))->saveQuietly();

    $tester = run_repair_embeddings_command(['model' => EmbeddableTestModel::class, '--if-idle' => true]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::SUCCESS);
    Queue::assertPushed(GenerateEmbeddingsJob::class, 1);
});

/**
 * @param  array<string, mixed>  $embed_extra
 */
function repair_fake_service_reporting(?string $health_model, array $embed_extra = []): void
{
    Http::fake([
        '*/info' => $health_model === null ? Http::response(null, 500) : Http::response(['model_id' => $health_model]),
        '*/v1/embeddings' => Http::response([
            ...$embed_extra,
            'data' => [['index' => 0, 'embedding' => ai_test_unit_vector(app(EmbeddingModelRegistry::class)->active()->dimensions)]],
        ]),
    ]);
}

it('aborts when the service reports another model than the active profile, so wrong vectors are never stamped', function (string $where, ?string $health_model, array $embed_extra): void {
    Queue::fake();
    repair_fake_service_reporting($health_model, $embed_extra);

    (new EmbeddableTestModel(['title' => 'Alpha']))->saveQuietly();

    $tester = run_repair_embeddings_command(['model' => EmbeddableTestModel::class]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::FAILURE)
        ->and($tester->getDisplay())->toContain($where)
        ->and($tester->getDisplay())->toContain('paraphrase-multilingual-MiniLM-L12-v2')
        ->and($tester->getDisplay())->toContain(app(EmbeddingModelRegistry::class)->active()->serviceModel);
    Queue::assertNothingPushed();
})->with([
    '/info, when the probe does not say' => ['/info', 'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2', []],
    '/v1/embeddings, which answers for the request' => ['/v1/embeddings', null, ['model' => 'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2']],
]);

it('aborts when the probe names the right model but /info names another, a second witness that must agree', function (): void {
    Queue::fake();
    $service_model = app(EmbeddingModelRegistry::class)->active()->serviceModel;
    repair_fake_service_reporting('some-default-model', ['model' => $service_model]);

    (new EmbeddableTestModel(['title' => 'Alpha']))->saveQuietly();

    $tester = run_repair_embeddings_command(['model' => EmbeddableTestModel::class]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::FAILURE)
        ->and($tester->getDisplay())->toContain('/info reports model "some-default-model"');
    Queue::assertNothingPushed();
});

it('accepts the same model named without its organisation prefix', function (): void {
    Queue::fake();
    $short_name = basename(app(EmbeddingModelRegistry::class)->active()->serviceModel);
    repair_fake_service_reporting($short_name);

    (new EmbeddableTestModel(['title' => 'Alpha']))->saveQuietly();

    $tester = run_repair_embeddings_command(['model' => EmbeddableTestModel::class]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::SUCCESS);
});

it('warns, and goes on, when the service does not say which model it runs', function (): void {
    Queue::fake();
    repair_fake_service_reporting(null);

    (new EmbeddableTestModel(['title' => 'Alpha']))->saveQuietly();

    $tester = run_repair_embeddings_command(['model' => EmbeddableTestModel::class]);

    expect($tester->getStatusCode())->toBe(RepairMissingEmbeddingsCommand::SUCCESS)
        ->and($tester->getDisplay())->toContain('does not report which model');
    Queue::assertPushed(GenerateEmbeddingsJob::class, 1);
});
