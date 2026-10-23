<?php

declare(strict_types=1);

use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelRegistry;

it('builds the vision profile from the vision choice', function (): void {
    config()->set('ai.features.media_analysis.vision.model', 'anthropic:claude-sonnet-5');

    $profile = (new MediaAnalysisModelRegistry())->active('vision');

    expect($profile)->toBeInstanceOf(MediaAnalysisModelProfile::class)
        ->and($profile->capability)->toBe('vision')
        ->and($profile->key)->toBe('anthropic:claude-sonnet-5')
        ->and($profile->provider)->toBe('anthropic')
        ->and($profile->serviceModel)->toBe('claude-sonnet-5');
});

it('builds the transcription profile on the OpenAI-compatible server', function (): void {
    $profile = (new MediaAnalysisModelRegistry())->active('transcription');

    expect($profile->key)->toBe('openai_compatible:Systran/faster-whisper-base')
        ->and($profile->provider)->toBe('openai_compatible')
        ->and($profile->serviceModel)->toBe('Systran/faster-whisper-base');
});

it('follows a changed vision setting', function (): void {
    config()->set('ai.features.media_analysis.vision.model', 'ollama:llava:13b');

    expect((new MediaAnalysisModelRegistry())->active('vision')->serviceModel)->toBe('llava:13b');
});

it('throws on an unknown capability', function (): void {
    (new MediaAnalysisModelRegistry())->active('nope');
})->throws(InvalidArgumentException::class);
