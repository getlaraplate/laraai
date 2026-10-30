<?php

declare(strict_types=1);

use Modules\AI\Tests\Stubs\ApplicationContent\EvaluationCommandContentProvider;
use Modules\Core\ApplicationContent\ApplicationContentRetrievalProviderRegistry;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderRegistryInterface;

it('writes a payload-free provider evaluation report and refuses accidental overwrite', function (): void {
    $registry = new ApplicationContentRetrievalProviderRegistry;
    $registry->register(new EvaluationCommandContentProvider);
    app()->instance(ApplicationContentRetrievalProviderRegistryInterface::class, $registry);
    $directory = sys_get_temp_dir() . '/laraplate-ai-evaluation-' . bin2hex(random_bytes(5));
    mkdir($directory, 0700, true);
    $dataset_path = $directory . '/dataset.json';
    $output_path = $directory . '/report.json';
    file_put_contents($dataset_path, json_encode([
        'source' => 'cms.evaluation_records',
        'data_classification' => 'synthetic',
        'version' => '1',
        'provider_version' => 'fake-v1',
        'corpus_revision' => 'generated-1',
        'cases' => [[
            'id' => 'exact',
            'query' => 'private evaluation query',
            'locale' => 'en',
            'limit' => 5,
            'expected_hit_ids' => ['cms.evaluation_records:1'],
            'expected_citation_references' => ['/app/cms/contents/1'],
            'expect_authorized_empty' => false,
            'expect_supported_answer' => true,
            'expect_abstention' => false,
            'slices' => ['exact'],
            'authorization' => [
                'permission' => 'evaluation.contents.select',
                'filters' => null,
            ],
        ]],
    ], JSON_THROW_ON_ERROR));

    try {
        $this->artisan('test:ai:evaluate-application-content', [
            '--dataset' => $dataset_path,
            '--source' => 'cms.evaluation_records',
            '--output' => $output_path,
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($output_path), true, flags: JSON_THROW_ON_ERROR);

        expect($report['metrics']['hit_at_5'])->toBe(1.0)
            ->and($report['metrics']['citation_precision'])->toBe(1.0)
            ->and($report['metrics']['precision_at_5'])->toBe(0.2)
            ->and($report['metrics']['recall_at_5'])->toBe(1.0)
            ->and($report['metrics']['ndcg_at_5'])->toBe(1.0)
            ->and($report['source'])->toBe('cms.evaluation_records')
            ->and(json_encode($report))->not->toContain('private evaluation query')
            ->and(json_encode($report))->not->toContain('evaluation.contents.select');

        $this->artisan('test:ai:evaluate-application-content', [
            '--dataset' => $dataset_path,
            '--source' => 'cms.evaluation_records',
            '--output' => $output_path,
        ])->assertFailed();
    } finally {
        @unlink($output_path);
        @unlink($dataset_path);
        @rmdir($directory);
    }
});

it('fails before evaluation for an unknown source', function (): void {
    $registry = new ApplicationContentRetrievalProviderRegistry;
    app()->instance(ApplicationContentRetrievalProviderRegistryInterface::class, $registry);

    $this->artisan('test:ai:evaluate-application-content', [
        '--dataset' => '/missing/dataset.json',
        '--source' => 'missing.records',
        '--output' => '/tmp/missing-report.json',
    ])->assertFailed();
});
