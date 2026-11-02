<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Services\ApplicationContent\Evaluation\Contracts\PerStrategyEngineRetrieverInterface;
use Modules\AI\Tests\Stubs\ApplicationContent\FakePerStrategyEngineRetriever;
use Modules\AI\Tests\Stubs\ApplicationContent\RetrievalStrategyCommandContentProvider;
use Modules\AI\Tests\Stubs\ApplicationContent\SimilarityReportingUser;
use Modules\Core\ApplicationContent\ApplicationContentRetrievalProviderRegistry;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderRegistryInterface;
use Modules\Core\Models\User;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\DTOs\AdvancedSearchResult;

/**
 * @param  list<string>  $orderedIds
 * @param  array<string, float>  $scores  the score of an id, 1.0 when not given
 * @return array<string, array{id: string, score: float, raw_score: float, score_details: array<never>, source: array<never>, rank: int}>
 */
function tuneCommandRanking(array $orderedIds, array $scores = []): array
{
    $ranked = [];

    foreach ($orderedIds as $index => $id) {
        $score = $scores[$id] ?? 1.0;
        $ranked[$id] = ['id' => $id, 'score' => $score, 'raw_score' => $score, 'score_details' => [], 'source' => [], 'rank' => $index + 1];
    }

    return $ranked;
}

/**
 * @param  list<string>  $finalIds
 * @param  array<string, mixed>  $meta
 */
function tuneCommandResult(array $finalIds, array $meta = []): AdvancedSearchResult
{
    $hits = array_map(static fn (string $id): array => ['id' => $id, 'score' => 1.0, 'raw_score' => 1.0, 'score_details' => [], 'source' => []], $finalIds);

    return new AdvancedSearchResult(hits: $hits, total: count($hits), page: 1, perPage: 5, totalPages: 1, meta: $meta);
}

/**
 * Registers the fake source, retriever and embedder, and writes a one-case dataset.
 *
 * @param  bool  $rerankerRan  whether the reranked retrieval carries `meta['reranked'] = true`, as it does when the service answered
 * @param  class-string<User>  $model  the permission model of the source, whose engine the similarity floor is measured with
 * @param  array<string, float>  $vectorScores  the score of the vector hits by id, 1.0 when not given
 * @return array{directory: string, dataset: string, output: string, retriever: FakePerStrategyEngineRetriever}
 */
function tuneCommandFixture(bool $rerankerRan = false, string $model = User::class, array $vectorScores = []): array
{
    $registry = new ApplicationContentRetrievalProviderRegistry;
    $registry->register(new RetrievalStrategyCommandContentProvider($model));
    app()->instance(ApplicationContentRetrievalProviderRegistryInterface::class, $registry);

    $retriever = new FakePerStrategyEngineRetriever(
        tuneCommandResult(['1', '2'], ['per_strategy' => [
            'keyword' => tuneCommandRanking(['1', '2']),
            'vector' => tuneCommandRanking(['2', '1'], $vectorScores),
            'hybrid' => tuneCommandRanking(['2', '1']),
        ]]),
        tuneCommandResult(['2', '1'], $rerankerRan ? ['reranked' => true] : []),
    );
    app()->instance(PerStrategyEngineRetrieverInterface::class, $retriever);

    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2, 0.3]);
    app()->instance(ITextEmbedder::class, $embedder);

    $directory = sys_get_temp_dir() . '/laraplate-ai-tune-retrieval-' . bin2hex(random_bytes(5));
    mkdir($directory, 0700, true);
    $dataset = $directory . '/dataset.json';
    file_put_contents($dataset, json_encode([
        'source' => 'cms.strategy_records',
        'data_classification' => 'synthetic',
        'version' => '1',
        'provider_version' => 'fake-v1',
        'corpus_revision' => 'generated-1',
        'cases' => [[
            'id' => 'names',
            'query' => 'Mario Rossi',
            'locale' => 'en',
            'limit' => 5,
            'expected_hit_ids' => ['cms.strategy_records:2'],
            'expected_citation_references' => [],
            'expect_authorized_empty' => false,
            'expect_supported_answer' => false,
            'expect_abstention' => false,
            'slices' => [],
            'authorization' => ['permission' => 'evaluation.contents.select', 'filters' => null],
        ]],
    ], JSON_THROW_ON_ERROR));

    return ['directory' => $directory, 'dataset' => $dataset, 'output' => $directory . '/report.json', 'retriever' => $retriever];
}

function tuneCommandCleanup(string $directory): void
{
    foreach (glob($directory . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir($directory);
}

it('fails before tuning for an unregistered source', function (): void {
    app()->instance(ApplicationContentRetrievalProviderRegistryInterface::class, new ApplicationContentRetrievalProviderRegistry);

    $this->artisan('test:ai:tune-retrieval', [
        '--dataset' => '/missing/dataset.json',
        '--source' => 'missing.records',
        '--output' => '/tmp/missing-tuning-report.json',
    ])->assertFailed();
});

it('fails for an unknown metric or grid without writing a report', function (array $options): void {
    $fixture = tuneCommandFixture();

    try {
        $this->artisan('test:ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
            ...$options,
        ])->assertFailed();

        expect(file_exists($fixture['output']))->toBeFalse();
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
})->with([
    'metric' => [['--metric' => 'ndcg_at_7']],
    'grid' => [['--grid' => 'exhaustive']],
]);

it('writes only its report, prints a profile block and refuses to overwrite without force', function (): void {
    $fixture = tuneCommandFixture();
    $profile_path = module_path('Core', 'config/search_tuning.php');
    $profile_hash = hash_file('sha256', $profile_path);

    try {
        // The noise check is off here: this test is about what the command writes, and a one-case
        // dataset cannot clear any noise margin (see the noise tests below).
        $this->artisan('test:ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
            '--noise-margin' => '0',
        ])
            ->expectsOutputToContain("'default' => [")
            ->expectsOutputToContain("'classes' => [")
            ->assertSuccessful();

        $report = json_decode((string) file_get_contents($fixture['output']), true, flags: JSON_THROW_ON_ERROR);

        expect($report['source'])->toBe('cms.strategy_records')
            ->and($report['metric'])->toBe('ndcg_at_5')
            ->and($report['case_count'])->toBe(1)
            ->and($report['class_counts'])->toBe(['short_keyword' => 1])
            ->and($report['candidates'])->toHaveCount(108)
            ->and($report['winner']['params'])->toHaveKeys(['keyword_weight', 'vector_weight', 'hybrid_weight'])
            ->and($fixture['retriever']->calls)->toHaveCount(2)
            ->and(array_map(basename(...), glob($fixture['directory'] . '/*') ?: []))->toBe(['dataset.json', 'report.json'])
            ->and(hash_file('sha256', $profile_path))->toBe($profile_hash);

        $this->artisan('test:ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
        ])->assertFailed();
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
});

it('refuses safeguards outside their range before tuning anything', function (array $options): void {
    $fixture = tuneCommandFixture();

    try {
        $this->artisan('test:ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
            ...$options,
        ])->assertFailed();

        expect(file_exists($fixture['output']))->toBeFalse()
            ->and($fixture['retriever']->calls)->toBe([]);
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
})->with([
    'held-out share above one half' => [['--holdout' => '0.9']],
    'held-out share not a number' => [['--holdout' => 'lots']],
    'no class cases' => [['--min-class-cases' => '0']],
    'margin above one' => [['--class-margin' => '2']],
    'noise margin above one' => [['--noise-margin' => '2']],
    'noise margin not a number' => [['--noise-margin' => 'lots']],
]);

it('applies the recommended safeguards by default and records them in the report', function (): void {
    $fixture = tuneCommandFixture();

    try {
        $this->artisan('test:ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
        ])
            ->expectsOutputToContain('Validation')
            ->assertSuccessful();

        $report = json_decode((string) file_get_contents($fixture['output']), true, flags: JSON_THROW_ON_ERROR);

        expect($report['safeguards'])->toBe(['holdout_fraction' => 0.3, 'min_class_cases' => 8, 'class_margin' => 0.01, 'noise_margin' => 'auto'])
            ->and($report['validation']['status'])->toBeIn(['skipped', 'passed', 'failed']);
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
});

it('turns the held-out validation off with a zero share, and says the profile was not validated', function (): void {
    $fixture = tuneCommandFixture();

    try {
        $this->artisan('test:ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
            '--holdout' => '0',
            '--min-class-cases' => '1',
            '--class-margin' => '0',
            '--noise-margin' => '0',
        ])
            ->expectsOutputToContain('not validated on held-out cases')
            ->assertSuccessful();

        $report = json_decode((string) file_get_contents($fixture['output']), true, flags: JSON_THROW_ON_ERROR);

        expect($report['validation']['status'])->toBe('disabled')
            ->and($report['noise']['status'])->toBe('disabled')
            ->and($report['safeguards'])->toBe(['holdout_fraction' => 0.0, 'min_class_cases' => 1, 'class_margin' => 0.0, 'noise_margin' => 0.0]);
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
});

it('declares no winner for a dataset too small to clear the automatic noise margin, and says why', function (): void {
    $fixture = tuneCommandFixture();

    try {
        $this->artisan('test:ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
        ])
            ->expectsOutputToContain('no winner')
            ->expectsOutputToContain('keep the committed profile')
            ->assertSuccessful();

        $report = json_decode((string) file_get_contents($fixture['output']), true, flags: JSON_THROW_ON_ERROR);

        expect($report['winner'])->toBeNull()
            ->and($report['noise']['status'])->toBe('within_noise')
            ->and($report['noise']['selection_cases'])->toBe(1)
            ->and($report['noise']['margin'])->toBe(1.0);
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
});

it('records a fixed noise margin given on the command line', function (): void {
    $fixture = tuneCommandFixture();

    try {
        $this->artisan('test:ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
            '--noise-margin' => '0.05',
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($fixture['output']), true, flags: JSON_THROW_ON_ERROR);

        expect($report['safeguards']['noise_margin'])->toBe(0.05)
            ->and($report['noise']['margin'])->toBe(0.05);
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
});

it('retrieves every case in the locale the case declares', function (): void {
    $fixture = tuneCommandFixture();

    try {
        $this->artisan('test:ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
            '--noise-margin' => '0',
        ])->assertSuccessful();

        expect($fixture['retriever']->calls)->not->toBe([])
            ->and(array_unique(array_column($fixture['retriever']->calls, 'locale')))->toBe(['en']);
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
});

it('records the context a profile was measured in: the embedding model, the corpus size and the dataset fingerprint', function (): void {
    $fixture = tuneCommandFixture();

    try {
        $this->artisan('test:ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
            '--noise-margin' => '0',
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($fixture['output']), true, flags: JSON_THROW_ON_ERROR);
        $active = app(EmbeddingModelRegistry::class)->active();

        // A profile only holds for the model whose vectors it was measured on, and a margin only for
        // the corpus it was measured on: the report says which, so a stale profile can be told.
        expect($report['embedding'])->toBe([
            'profile' => $active->key,
            'service_model' => $active->serviceModel,
            'dimensions' => $active->dimensions,
        ])
            ->and($report['corpus']['size'])->toBeInt()
            // Whoever holds the private dataset can check it is the one measured; nobody else sees it.
            ->and($report['dataset']['sha256'])->toBe(hash_file('sha256', $fixture['dataset']));
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
});

it('warns when the reranker did not run, and records it in the report', function (): void {
    $fixture = tuneCommandFixture(rerankerRan: false);

    try {
        $this->artisan('test:ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
            '--noise-margin' => '0',
        ])
            ->expectsOutputToContain('Reranker did not run')
            ->assertSuccessful();

        $report = json_decode((string) file_get_contents($fixture['output']), true, flags: JSON_THROW_ON_ERROR);

        expect($report['reranker'])->toBe(['requested' => 1, 'ran' => 0, 'status' => 'not_run', 'model' => null]);
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
});

it('says nothing about the reranker when it ran', function (): void {
    $fixture = tuneCommandFixture(rerankerRan: true);

    try {
        $this->artisan('test:ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
            '--noise-margin' => '0',
        ])
            ->doesntExpectOutputToContain('Reranker')
            ->assertSuccessful();

        $report = json_decode((string) file_get_contents($fixture['output']), true, flags: JSON_THROW_ON_ERROR);

        expect($report['reranker'])->toBe(['requested' => 1, 'ran' => 1, 'status' => 'ran', 'model' => null]);
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
});

it('prints a recommended similarity floor measured on the vector hits, and writes it nowhere', function (): void {
    config()->set('ai.features.embeddings.enabled', true);
    config()->set('ai.features.embeddings.models', ['test:floor-model' => ['dimensions' => 3, 'similarity' => 'cosine']]);
    config()->set('ai.features.embeddings.serving_model', 'test:floor-model');
    $fixture = tuneCommandFixture(model: SimilarityReportingUser::class, vectorScores: ['2' => 0.8234, '1' => 0.64]);
    $profile_hash = hash_file('sha256', module_path('Core', 'config/search_tuning.php'));

    try {
        $this->artisan('test:ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
            '--noise-margin' => '0',
        ])
            ->expectsOutputToContain("never written: set 'min_similarity' on the profile 'test:floor-model' by hand): 0.82,")
            ->assertSuccessful();

        $report = (string) file_get_contents($fixture['output']);

        expect($report)->not->toContain('min_similarity')
            ->and($report)->not->toContain('Similarity floor')
            ->and(config('ai.features.embeddings.models.test:floor-model'))->not->toHaveKey('min_similarity')
            ->and(array_map(basename(...), glob($fixture['directory'] . '/*') ?: []))->toBe(['dataset.json', 'report.json'])
            ->and(hash_file('sha256', module_path('Core', 'config/search_tuning.php')))->toBe($profile_hash);
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
});

it('says why it cannot recommend a similarity floor when the engine does not tell the similarity', function (): void {
    $fixture = tuneCommandFixture();

    try {
        $this->artisan('test:ai:tune-retrieval', [
            '--dataset' => $fixture['dataset'],
            '--source' => 'cms.strategy_records',
            '--output' => $fixture['output'],
            '--noise-margin' => '0',
        ])
            ->expectsOutputToContain('Similarity floor: no recommendation')
            ->assertSuccessful();
    } finally {
        tuneCommandCleanup($fixture['directory']);
    }
});
