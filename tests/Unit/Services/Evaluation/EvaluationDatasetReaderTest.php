<?php

declare(strict_types=1);

use Modules\AI\Services\Evaluation\EvaluationDatasetReader;

it('refuses a dataset file over two million bytes even when it holds fewer characters', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'dataset');
    file_put_contents($path, json_encode(['text' => str_repeat('€', 700_000)], JSON_UNESCAPED_UNICODE));

    try {
        expect(fn (): array => new EvaluationDatasetReader('Test')->readFile($path))
            ->toThrow(InvalidArgumentException::class, 'Test evaluation dataset is invalid.');
    } finally {
        unlink($path);
    }
});
