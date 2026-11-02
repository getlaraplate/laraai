<?php

declare(strict_types=1);

use Modules\AI\Ai\Embeddings\EmbeddingLanguageCheck;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;

/**
 * @param  list<string>  $languages
 */
function language_test_profile(array $languages): EmbeddingModelProfile
{
    return new EmbeddingModelProfile(
        key: 'openai_compatible:language-test',
        provider: 'openai_compatible',
        serviceModel: 'language-test',
        dimensions: 8,
        queryPrefix: '',
        passagePrefix: '',
        similarity: 'cosine',
        normalize: false,
        languages: $languages,
    );
}

it('treats undeclared languages as unknown and warns nothing', function (): void {
    $profile = language_test_profile([]);

    expect($profile->languages)->toBe([])
        ->and($profile->coversLanguages(['it', 'en', 'de']))->toBeTrue()
        ->and($profile->missingLanguages(['it', 'en', 'de']))->toBe([]);
});

it('keeps every existing constructor call working without languages', function (): void {
    $profile = new EmbeddingModelProfile('a:b', 'a', 'b', 4, '', '', 'cosine', false);

    expect($profile->languages)->toBe([])
        ->and($profile->coversLanguages(['it']))->toBeTrue();
});

it('names the installation locales a profile does not declare', function (): void {
    $profile = language_test_profile(['en', 'it']);

    expect($profile->coversLanguages(['en', 'it']))->toBeTrue()
        ->and($profile->coversLanguages(['en', 'it', 'de', 'sl']))->toBeFalse()
        ->and($profile->missingLanguages(['en', 'it', 'de', 'sl']))->toBe(['de', 'sl']);
});

it('covers every locale with a star', function (): void {
    $profile = language_test_profile(['*']);

    expect($profile->coversLanguages(['xx', 'yy']))->toBeTrue()
        ->and($profile->missingLanguages(['xx', 'yy']))->toBe([]);
});

it('compares languages by their code without case or region', function (): void {
    $profile = language_test_profile(['it_IT', 'EN-us']);

    expect($profile->languages)->toBe(['it', 'en'])
        ->and($profile->coversLanguages(['it-IT', 'en_GB', 'IT']))->toBeTrue()
        ->and($profile->missingLanguages(['de_DE', 'it-IT', 'de']))->toBe(['de']);
});

it('reads the declared languages of a profile from its config entry', function (): void {
    config([
        'ai.features.embeddings.models.openai_compatible:declared' => ['dimensions' => 8, 'languages' => ['it_IT', 'en']],
        'ai.features.embeddings.models.openai_compatible:everything' => ['dimensions' => 8, 'languages' => ['*']],
        'ai.features.embeddings.models.openai_compatible:silent' => ['dimensions' => 8],
    ]);
    $registry = app(EmbeddingModelRegistry::class);

    expect($registry->get('openai_compatible:declared')->languages)->toBe(['it', 'en'])
        ->and($registry->get('openai_compatible:everything')->languages)->toBe(['*'])
        ->and($registry->get('openai_compatible:silent')->languages)->toBe([]);
});

it('warns about the installation locales the profile misses, and only then', function (): void {
    config(['app.available_locales' => ['en', 'it', 'de']]);
    $check = new EmbeddingLanguageCheck;

    $warning = $check->warningFor(language_test_profile(['en']));

    expect($check->installationLocales())->toBe(['en', 'it', 'de'])
        ->and($warning)->toContain('openai_compatible:language-test')->toContain('it, de')->not->toContain('en,')
        ->and($check->warningFor(language_test_profile([])))->toBeNull()
        ->and($check->warningFor(language_test_profile(['*'])))->toBeNull()
        ->and($check->warningFor(language_test_profile(['it', 'en', 'de'])))->toBeNull();
});
