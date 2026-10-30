<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Modules\AI\Services\ApplicationContent\Evaluation\Contracts\PerStrategyEngineRetrieverInterface;
use Modules\AI\Tests\Stubs\ApplicationContent\FakePerStrategyEngineRetriever;
use Modules\AI\Tests\Stubs\ApplicationContent\RetrievalStrategyCommandContentProvider;
use Modules\Core\ApplicationContent\ApplicationContentRetrievalProviderRegistry;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderRegistryInterface;
use Modules\Core\Models\User;
use Modules\Core\Search\Contracts\ITextEmbedder;
use Modules\Core\Search\DTOs\AdvancedSearchResult;

/**
 * Builds a rank-ordered per-strategy hit map, mirroring what
 * `EnsembleSearchService` writes into `AdvancedSearchResult::$meta['per_strategy']`.
 *
 * @param  list<string>  $orderedIds
 * @return array<string, array{id: string, score: float, raw_score: float, score_details: array<never>, source: array<never>, rank: int}>
 */
function strategyCommandRanking(array $orderedIds): array
{
    $ranked = [];
    $rank = 1;

    foreach ($orderedIds as $id) {
        $ranked[$id] = [
            'id' => $id,
            'score' => 1.0,
            'raw_score' => 1.0,
            'score_details' => [],
            'source' => [],
            'rank' => $rank,
        ];
        $rank++;
    }

    return $ranked;
}

/**
 * @param  list<string>  $finalIds
 * @param  array<string, array<string, array{id: string, score: float, raw_score: float, score_details: array<never>, source: array<never>, rank: int}>>  $perStrategy
 */
function strategyCommandResult(array $finalIds, array $perStrategy = []): AdvancedSearchResult
{
    $hits = array_map(static fn (string $id): array => [
        'id' => $id,
        'score' => 1.0,
        'raw_score' => 1.0,
        'score_details' => [],
        'source' => [],
    ], $finalIds);

    return new AdvancedSearchResult(
        hits: $hits,
        total: count($hits),
        page: 1,
        perPage: max(1, count($hits)),
        totalPages: 1,
        meta: $perStrategy === [] ? [] : ['per_strategy' => $perStrategy],
    );
}

it('fails before evaluation for an unregistered source', function (): void {
    $registry = new ApplicationContentRetrievalProviderRegistry;
    app()->instance(ApplicationContentRetrievalProviderRegistryInterface::class, $registry);

    $this->artisan('test:ai:evaluate-retrieval-strategies', [
        '--dataset' => '/missing/dataset.json',
        '--source' => 'missing.records',
        '--output' => '/tmp/missing-strategy-report.json',
    ])->assertFailed();
});

it('writes a per-strategy report using a fake engine retriever, with no Elasticsearch involved', function (): void {
    $registry = new ApplicationContentRetrievalProviderRegistry;
    $registry->register(new RetrievalStrategyCommandContentProvider(User::class));
    app()->instance(ApplicationContentRetrievalProviderRegistryInterface::class, $registry);

    // Engine ids are bare (`EnsembleSearchService` emits `(string) $model->getKey()`), not
    // pre-namespaced with the source: the evaluation service must prefix them with
    // "cms.strategy_records:" before comparing against the dataset's `expected_hit_ids`.
    $off = strategyCommandResult(
        finalIds: ['1', '2'],
        perStrategy: [
            'keyword' => strategyCommandRanking(['1', '2']),
            'vector' => strategyCommandRanking(['1', '2']),
            'hybrid' => strategyCommandRanking(['1', '2']),
        ],
    );
    $on = strategyCommandResult(finalIds: ['1', '2']);
    $retriever = new FakePerStrategyEngineRetriever($off, $on);
    app()->instance(PerStrategyEngineRetrieverInterface::class, $retriever);

    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldReceive('embed')->once()->with('private strategy query')->andReturn([0.1, 0.2, 0.3]);
    app()->instance(ITextEmbedder::class, $embedder);

    $directory = sys_get_temp_dir() . '/laraplate-ai-strategy-evaluation-' . bin2hex(random_bytes(5));
    mkdir($directory, 0700, true);
    $dataset_path = $directory . '/dataset.json';
    $output_path = $directory . '/report.json';
    file_put_contents($dataset_path, json_encode([
        'source' => 'cms.strategy_records',
        'data_classification' => 'synthetic',
        'version' => '1',
        'provider_version' => 'fake-v1',
        'corpus_revision' => 'generated-1',
        'cases' => [[
            'id' => 'exact',
            'query' => 'private strategy query',
            'locale' => 'en',
            'limit' => 5,
            'expected_hit_ids' => ['cms.strategy_records:1'],
            'expected_citation_references' => [],
            'expect_authorized_empty' => false,
            'expect_supported_answer' => false,
            'expect_abstention' => false,
            'slices' => [],
            'authorization' => [
                'permission' => 'evaluation.contents.select',
                'filters' => null,
            ],
        ]],
    ], JSON_THROW_ON_ERROR));

    try {
        $this->artisan('test:ai:evaluate-retrieval-strategies', [
            '--dataset' => $dataset_path,
            '--source' => 'cms.strategy_records',
            '--output' => $output_path,
        ])->assertSuccessful();

        // Each case runs in the locale it declares: under another one a model's LocaleScope hides
        // the rows with no translation in it, and the evaluation would never see them.
        expect(array_unique(array_column($retriever->calls, 'locale')))->toBe(['en']);

        $report = json_decode((string) file_get_contents($output_path), true, flags: JSON_THROW_ON_ERROR);

        expect($report['source'])->toBe('cms.strategy_records')
            ->and($report['case_count'])->toBe(1)
            ->and($report['metrics'])->toHaveKeys(['keyword', 'vector', 'hybrid', 'fused', 'reranked']);

        foreach ($report['metrics'] as $strategy_metrics) {
            expect($strategy_metrics)->toHaveKeys([
                'precision_at_1', 'precision_at_3', 'precision_at_5',
                'recall_at_1', 'recall_at_3', 'recall_at_5',
                'ndcg_at_1', 'ndcg_at_3', 'ndcg_at_5',
            ]);
        }

        // Every strategy ranks the sole expected hit ("cms.strategy_records:1") first.
        // Asserting non-zero here (rather than just key presence) catches a regression where
        // the service compares bare engine ids against source-prefixed `expected_hit_ids`
        // and every metric silently collapses to 0.0.
        expect($report['metrics']['keyword']['precision_at_1'])->toBeGreaterThan(0.0)
            ->and($report['metrics']['vector']['precision_at_1'])->toBeGreaterThan(0.0)
            ->and($report['metrics']['hybrid']['precision_at_1'])->toBeGreaterThan(0.0)
            ->and($report['metrics']['fused']['precision_at_1'])->toBeGreaterThan(0.0)
            ->and($report['metrics']['reranked']['precision_at_1'])->toBeGreaterThan(0.0);

        // Both calls (useReranker false and true) reused the single embedding computed for the case.
        expect($retriever->calls)->toHaveCount(2)
            ->and($retriever->calls[0]['vector'])->toBe([0.1, 0.2, 0.3])
            ->and($retriever->calls[1]['vector'])->toBe([0.1, 0.2, 0.3])
            ->and($retriever->calls[0]['model'])->toBe(User::class);
    } finally {
        @unlink($output_path);
        @unlink($dataset_path);
        @rmdir($directory);
    }
});

/**
 * Runs the command once over a one-case dataset whose reranked retrieval does or does not carry the
 * flag `EnsembleSearchService` sets when the reranker answered.
 *
 * @return array{exit: int, output: string, report: array<string, mixed>}
 */
function strategyCommandRerankerRun(bool $rerankerRan): array
{
    $registry = new ApplicationContentRetrievalProviderRegistry;
    $registry->register(new RetrievalStrategyCommandContentProvider(User::class));
    app()->instance(ApplicationContentRetrievalProviderRegistryInterface::class, $registry);

    $off = strategyCommandResult(['1', '2'], [
        'keyword' => strategyCommandRanking(['1', '2']),
        'vector' => strategyCommandRanking(['1', '2']),
        'hybrid' => strategyCommandRanking(['1', '2']),
    ]);
    $on = new AdvancedSearchResult(hits: $off->hits, total: $off->total, page: 1, perPage: 2, totalPages: 1, meta: ['reranked' => $rerankerRan]);
    app()->instance(PerStrategyEngineRetrieverInterface::class, new FakePerStrategyEngineRetriever($off, $on));

    $embedder = Mockery::mock(ITextEmbedder::class);
    $embedder->shouldReceive('embed')->andReturn([0.1, 0.2, 0.3]);
    app()->instance(ITextEmbedder::class, $embedder);

    $directory = sys_get_temp_dir() . '/laraplate-ai-strategy-reranker-' . bin2hex(random_bytes(5));
    mkdir($directory, 0700, true);
    $dataset_path = $directory . '/dataset.json';
    $output_path = $directory . '/report.json';
    file_put_contents($dataset_path, json_encode([
        'source' => 'cms.strategy_records',
        'data_classification' => 'synthetic',
        'version' => '1',
        'provider_version' => 'fake-v1',
        'corpus_revision' => 'generated-1',
        'cases' => [[
            'id' => 'exact',
            'query' => 'strategy query',
            'locale' => 'en',
            'limit' => 5,
            'expected_hit_ids' => ['cms.strategy_records:1'],
            'expected_citation_references' => [],
            'expect_authorized_empty' => false,
            'expect_supported_answer' => false,
            'expect_abstention' => false,
            'slices' => [],
            'authorization' => ['permission' => 'evaluation.contents.select', 'filters' => null],
        ]],
    ], JSON_THROW_ON_ERROR));

    try {
        $exit = Artisan::call('test:ai:evaluate-retrieval-strategies', [
            '--dataset' => $dataset_path,
            '--source' => 'cms.strategy_records',
            '--output' => $output_path,
        ]);

        return [
            'exit' => $exit,
            'output' => Artisan::output(),
            'report' => json_decode((string) file_get_contents($output_path), true, flags: JSON_THROW_ON_ERROR),
        ];
    } finally {
        @unlink($output_path);
        @unlink($dataset_path);
        @rmdir($directory);
    }
}

it('warns when the reranker did not run, because the reranked figures are then the fused order', function (): void {
    $run = strategyCommandRerankerRun(rerankerRan: false);

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain('Reranker did not run')
        ->and($run['output'])->toContain('0 of 1')
        ->and($run['report']['reranker'])->toBe(['requested' => 1, 'ran' => 0, 'status' => 'not_run', 'model' => null]);
});

it('says nothing about the reranker when it ran', function (): void {
    $run = strategyCommandRerankerRun(rerankerRan: true);

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->not->toContain('Reranker')
        ->and($run['report']['reranker'])->toBe(['requested' => 1, 'ran' => 1, 'status' => 'ran', 'model' => null]);
});
