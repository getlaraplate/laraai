<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

/**
 * Compares the languages an embedding profile declares with the languages of the installation, which
 * are its enabled locales (`app.available_locales`, else the locales that have translations). A
 * mismatch is only a warning text: a profile that declares nothing, or `*`, never warns.
 */
final readonly class EmbeddingLanguageCheck
{
    /**
     * The enabled locales of the installation, as language codes.
     *
     * @return list<string>
     */
    public function installationLocales(): array
    {
        return EmbeddingModelProfile::normalizeLocales((array) config('app.available_locales', translations()));
    }

    /**
     * The installation locales the profile does not declare.
     *
     * @return list<string>
     */
    public function missingFor(EmbeddingModelProfile $profile): array
    {
        return $profile->missingLanguages($this->installationLocales());
    }

    /**
     * The translated warning naming the missing locales, or null when there is nothing to warn about.
     */
    public function warningFor(EmbeddingModelProfile $profile): ?string
    {
        $missing = $this->missingFor($profile);

        if ($missing === []) {
            return null;
        }

        return __('ai.messages.embeddingLanguagesMissing', [
            'profile' => $profile->key,
            'languages' => implode(', ', $missing),
        ]);
    }
}
