<?php

declare(strict_types=1);

use Modules\AI\Ai\Rag\Retrieval\DeveloperDocumentationRetrieval;
use Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval;
use Modules\AI\Tests\Stubs\Documentation\FakeDocumentationSearch;

beforeEach(function (): void {
    config()->set('ai.features.faq.max_documents', 5);
    config()->set('ai.features.faq.policy_classification_version', 'in-app-docs-v1');
});

it('writes a payload-free documentation evaluation report and refuses overwrite', function (): void {
    app()->instance(InAppDocumentationRetrieval::class, FakeDocumentationSearch::forInAppRetrieval([
        'how do I export a grid?' => [
            FakeDocumentationSearch::document('Core · Grid export', 'en', 'Use export.', ['Core', 'Grid export']),
        ],
    ]));

    $directory = sys_get_temp_dir() . '/laraplate-doc-eval-' . bin2hex(random_bytes(5));
    mkdir($directory, 0700, true);
    $dataset_path = $directory . '/dataset.json';
    $output_path = $directory . '/report.json';
    file_put_contents($dataset_path, json_encode([
        'version' => '1', 'corpus_revision' => 'core-1', 'module' => 'core',
        'index_profile' => 'user', 'data_classification' => 'synthetic',
        'cases' => [[
            'id' => 'hit', 'query' => 'how do I export a grid?', 'locale' => 'en', 'top_k' => 5,
            'expected_source_labels' => ['Core · Grid export'],
            'expected_citation_labels' => ['Core · Grid export'],
            'expect_authorized_empty' => false, 'expect_supported_answer' => true, 'expect_refusal' => false,
            'slices' => ['grid'], 'tenant_scope' => 'global', 'tenant_id' => null, 'effective_permissions' => [],
        ]],
    ], JSON_THROW_ON_ERROR));

    try {
        $this->artisan('test:ai:evaluate-documentation', [
            '--module' => 'core', '--index' => 'user',
            '--dataset' => $dataset_path, '--output' => $output_path,
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($output_path), true, flags: JSON_THROW_ON_ERROR);

        expect($report['metrics']['source_hit_at_k'])->toBe(1.0)
            ->and($report['module'])->toBe('core')
            ->and(json_encode($report))->not->toContain('how do I export a grid?');

        $this->artisan('test:ai:evaluate-documentation', [
            '--module' => 'core', '--index' => 'user',
            '--dataset' => $dataset_path, '--output' => $output_path,
        ])->assertFailed();
    } finally {
        @unlink($output_path);
        @unlink($dataset_path);
        @rmdir($directory);
    }
});

it('evaluates the developer documentation index', function (): void {
    app()->instance(DeveloperDocumentationRetrieval::class, FakeDocumentationSearch::forDeveloperRetrieval([
        'how do I register a module?' => [
            FakeDocumentationSearch::document('Core · Module registration', 'en', 'Add it to module.json.', ['Core', 'Modules']),
        ],
    ]));

    $directory = sys_get_temp_dir() . '/laraplate-doc-eval-' . bin2hex(random_bytes(5));
    mkdir($directory, 0700, true);
    $dataset_path = $directory . '/dataset.json';
    $output_path = $directory . '/report.json';
    file_put_contents($dataset_path, json_encode([
        'version' => '1', 'corpus_revision' => 'core-1', 'module' => 'core',
        'index_profile' => 'developer', 'data_classification' => 'synthetic',
        'cases' => [[
            'id' => 'hit', 'query' => 'how do I register a module?', 'locale' => 'en', 'top_k' => 5,
            'expected_source_labels' => ['Core · Module registration'],
            'expected_citation_labels' => ['Core · Module registration'],
            'expect_authorized_empty' => false, 'expect_supported_answer' => true, 'expect_refusal' => false,
            'slices' => ['modules'], 'tenant_scope' => 'global', 'tenant_id' => null, 'effective_permissions' => [],
        ]],
    ], JSON_THROW_ON_ERROR));

    try {
        $this->artisan('test:ai:evaluate-documentation', [
            '--module' => 'core', '--index' => 'developer',
            '--dataset' => $dataset_path, '--output' => $output_path,
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($output_path), true, flags: JSON_THROW_ON_ERROR);

        expect($report['metrics']['source_hit_at_k'])->toBe(1.0)
            ->and($report['index_profile'])->toBe('developer');
    } finally {
        @unlink($output_path);
        @unlink($dataset_path);
        @rmdir($directory);
    }
});

it('fails when the dataset module does not match --module', function (): void {
    app()->instance(InAppDocumentationRetrieval::class, FakeDocumentationSearch::forInAppRetrieval([]));

    $directory = sys_get_temp_dir() . '/laraplate-doc-eval-' . bin2hex(random_bytes(5));
    mkdir($directory, 0700, true);
    $dataset_path = $directory . '/dataset.json';
    $output_path = $directory . '/report.json';
    file_put_contents($dataset_path, json_encode([
        'version' => '1', 'corpus_revision' => 'core-1', 'module' => 'core',
        'index_profile' => 'user', 'data_classification' => 'synthetic',
        'cases' => [[
            'id' => 'hit', 'query' => 'how do I export a grid?', 'locale' => 'en', 'top_k' => 5,
            'expected_source_labels' => ['Core · Grid export'],
            'expected_citation_labels' => ['Core · Grid export'],
            'expect_authorized_empty' => false, 'expect_supported_answer' => true, 'expect_refusal' => false,
            'slices' => ['grid'], 'tenant_scope' => 'global', 'tenant_id' => null, 'effective_permissions' => [],
        ]],
    ], JSON_THROW_ON_ERROR));

    try {
        $this->artisan('test:ai:evaluate-documentation', [
            '--module' => 'erp', '--index' => 'user',
            '--dataset' => $dataset_path, '--output' => $output_path,
        ])->assertFailed();

        expect(is_file($output_path))->toBeFalse();
    } finally {
        @unlink($output_path);
        @unlink($dataset_path);
        @rmdir($directory);
    }
});
