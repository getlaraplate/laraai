<?php

declare(strict_types=1);

use Modules\AI\Ai\Providers\ProviderFactory;
use Modules\AI\Exceptions\MediaAnalysisException;
use Modules\AI\Exceptions\TranslationException;
use Modules\Core\Exceptions\ConfigurationException;

test('ai exceptions use the expected base types', function (): void {
    expect(TranslationException::class)->toExtend(RuntimeException::class)
        ->and(MediaAnalysisException::class)->toExtend(RuntimeException::class);
});

test('provider factory throws invalid argument for unsupported provider', function (): void {
    ProviderFactory::make('unsupported-provider');
})->throws(InvalidArgumentException::class, 'Unsupported AI provider: unsupported-provider');

test('provider factory throws configuration exception when openai api key is missing', function (): void {
    config()->set('ai.providers.openai.api_key', '');
    config()->set('ai.providers.openai.base_url', '');

    ProviderFactory::make('openai');
})->throws(ConfigurationException::class, 'OpenAI API key is not configured');

test('translation exception exposes http status code', function (): void {
    $exception = new TranslationException('DeepL translation failed: 429', 429);

    expect($exception->statusCode)->toBe(429)
        ->and($exception->getMessage())->toBe('DeepL translation failed: 429');
});
