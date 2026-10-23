<?php

declare(strict_types=1);

$aiServerVariables = [
    'AI_EMBEDDINGS_URL', 'AI_EMBEDDINGS_API_KEY', 'AI_EMBEDDINGS_TIMEOUT', 'AI_EMBEDDINGS_BATCH_SIZE',
    'AI_EMBEDDINGS_MINILM_URL', 'AI_EMBEDDINGS_MINILM_API_KEY',
    'AI_RERANK_URL', 'AI_RERANK_API_KEY', 'AI_RERANK_DIALECT', 'AI_RERANK_MODEL',
    'AI_TRANSCRIPTION_URL', 'AI_TRANSCRIPTION_API_KEY', 'AI_TRANSCRIPTION_TIMEOUT',
];

it('lists every AI server variable in .env.example', function () use ($aiServerVariables): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    foreach ($aiServerVariables as $name) {
        expect($example)->toMatch('/^\s*#?\s*' . $name . '=/m');
    }
});

it('lists none of the variables of the removed services in .env.example', function (): void {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->not->toMatch('/^\s*#?\s*(SENTENCE_TRANSFORMERS_|CROSS_ENCODER_|WHISPER_)/m');
});

it('reads every AI server variable in the module config', function () use ($aiServerVariables): void {
    $config = (string) file_get_contents(dirname(__DIR__, 2) . '/config/config.php');

    foreach ($aiServerVariables as $name) {
        expect($config)->toContain("'" . $name . "'");
    }
});
