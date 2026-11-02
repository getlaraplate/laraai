<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use Modules\Core\Search\DTOs\VectorProfile;

/**
 * Immutable description of a single embedding model's attributes: which
 * provider serves it, its output dimensionality and similarity metric, and
 * the query/passage prefixes it expects (e.g. E5-family instruction
 * prefixes), and optionally the name of the server in `ai.servers` that runs it, the
 * languages it is declared to handle and its similarity floor. Resolved from config by
 * EmbeddingModelRegistry.
 */
final readonly class EmbeddingModelProfile
{
    /**
     * The language codes the profile is declared to handle, lowercase and without region (`it_IT`
     * and `it-IT` are `it`), or `['*']` for any language. Empty means the languages are unknown.
     *
     * @var list<string>
     */
    public array $languages;

    /**
     * @param  array<array-key, mixed>  $languages  locale codes, or `['*']`; empty when unknown
     * @param  float|null  $minSimilarity  the lowest similarity a vector hit needs to count, on the scale of the
     *                                     similarity function itself (the cosine for `cosine`), not the engine
     *                                     score; null for no floor. See {@see VectorProfile::$minSimilarity}.
     */
    public function __construct(
        public string $key,
        public string $provider,
        public string $serviceModel,
        public int $dimensions,
        public string $queryPrefix,
        public string $passagePrefix,
        public string $similarity,
        public bool $normalize,
        public ?string $server = null,
        array $languages = [],
        public ?float $minSimilarity = null,
    ) {
        $this->languages = self::normalizeLocales($languages);
    }

    /**
     * Lowercase codes without region or script (`it_IT`, `it-IT` and `IT` are `it`), without
     * blanks or duplicates; a star anywhere makes the whole list `['*']`.
     *
     * @param  array<array-key, mixed>  $locales
     * @return list<string>
     */
    public static function normalizeLocales(array $locales): array
    {
        $codes = [];

        foreach ($locales as $locale) {
            if (! is_string($locale)) {
                continue;
            }

            $code = mb_strtolower(mb_trim($locale));
            $code = $code === '*' ? $code : (string) preg_split('/[-_]/', $code, 2)[0];

            if ($code === '*') {
                return ['*'];
            }

            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * Whether two names designate the same service model: the last path segment, without case or
     * spaces, so `sentence-transformers/all-MiniLM-L6-v2` is `all-MiniLM-L6-v2` and a name that only
     * ends the same is not. A service reports a model with or without its organisation.
     */
    public static function sameServiceModel(string $first, string $second): bool
    {
        $normalize = static fn (string $name): string => mb_strtolower(basename(str_replace('\\', '/', mb_trim($name))));

        $first = $normalize($first);

        return $first !== '' && $first === $normalize($second);
    }

    /**
     * Whether the profile is declared to handle every given locale. Unknown languages and `*` cover
     * everything: an undeclared profile is not reported as lacking one.
     *
     * @param  array<array-key, mixed>  $locales
     */
    public function coversLanguages(array $locales): bool
    {
        return $this->missingLanguages($locales) === [];
    }

    /**
     * The given locales (as codes, in the order given) the profile does not declare; empty when its
     * languages are unknown or `*`.
     *
     * @param  array<array-key, mixed>  $locales
     * @return list<string>
     */
    public function missingLanguages(array $locales): array
    {
        if ($this->languages === [] || $this->languages === ['*']) {
            return [];
        }

        return array_values(array_diff(self::normalizeLocales($locales), $this->languages));
    }

    /**
     * The vector profile Core indexes and queries this model's vectors with.
     */
    public function vectorProfile(): VectorProfile
    {
        return new VectorProfile($this->key, $this->dimensions, $this->similarity, $this->minSimilarity);
    }
}
