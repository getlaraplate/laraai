<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationDataset;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationService;
use Modules\AI\Tests\Stubs\Documentation\CoreDeveloperDocumentationCorpus;

beforeEach(function (): void {
    config()->set('ai.features.faq.max_documents', 5);
});

it('keeps the Core developer documentation harness aligned with its committed dataset', function (): void {
    $path = base_path('Modules/AI/docs/rag/evaluations/2026-09-17-developer-core-baseline.json');
    $dataset = DocumentationEvaluationDataset::fromFile($path);
    $retrieval = CoreDeveloperDocumentationCorpus::retrieval();

    $report = (new DocumentationEvaluationService)->evaluate(
        $dataset,
        'fixture',
        static fn (string $q, $access): array => $retrieval->retrieve($q),
    );

    // Plumbing/alignment gate over a deterministic fixture whose labels match the
    // dataset. Real-Elasticsearch vector-only quality is the committed report
    // 2026-09-17-developer-core-vector-baseline.json (lower on keyword/near-dup),
    // and is measured by running `test:ai:evaluate-documentation --index=developer`.
    expect($report['metrics']['source_hit_at_k'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['mean_reciprocal_rank'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['citation_precision'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['supported_answer_rate'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['refusal_accuracy'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['unavailable_rate'])->toBe(0.0);
})->skip(fn (): bool => ! is_file(base_path('Modules/AI/docs/rag/evaluations/2026-09-17-developer-core-baseline.json')), 'Developer dataset missing');
