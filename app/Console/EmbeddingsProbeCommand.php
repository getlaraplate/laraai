<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionMismatch;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionProbe;
use Modules\AI\Ai\Embeddings\EmbeddingDriftReference;
use Modules\AI\Ai\Embeddings\EmbeddingDriftReferenceException;
use Modules\AI\Ai\Embeddings\EmbeddingLanguageCheck;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Override;
use Throwable;

/**
 * Embeds a fixed text with a configured profile and reports the vector length the model really
 * produces, failing when it differs from the dimensions the profile declares.
 *
 * With `--reference=save` it also embeds a fixed multilingual sample and stores the vectors; with
 * `--reference=compare` it embeds the sample again and fails when the lowest cosine against the
 * saved vectors is under `ai.features.embeddings.drift_floor`, which a change of server, runtime or
 * precision under the same model name can cause without changing the vector length.
 *
 * When the profile declares languages and misses some enabled locales of the installation it prints a
 * `warning:` line, never a failure. With `--cross-language` it also reports, as information only, the
 * cosine between the same sentence in Italian and in English.
 */
final class EmbeddingsProbeCommand extends Command
{
    #[Override]
    protected $signature = 'ai:embeddings:probe
                            {profile : Embedding model profile key, e.g. openai_compatible:intfloat/multilingual-e5-small}
                            {--reference= : save: store the vectors of a fixed sample as the reference; compare: fail when they drift below ai.features.embeddings.drift_floor}
                            {--cross-language : report the cosine of the same sentence in Italian and English, as information}';

    #[Override]
    protected $description = 'Measure the vector length an embedding profile really produces and compare it with its declared dimensions <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(EmbeddingModelRegistry $registry, EmbeddingDimensionProbe $probe, EmbeddingDriftReference $reference, EmbeddingLanguageCheck $languages): int
    {
        $mode = (string) ($this->option('reference') ?? '');

        if (! in_array($mode, ['', 'save', 'compare'], true)) {
            $this->error('The --reference option takes "save" or "compare".');

            return self::FAILURE;
        }

        try {
            $profile = $registry->get((string) $this->argument('profile'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $vectors = [];

        try {
            if ($mode === '') {
                $measured = $probe->verify($profile);
            } else {
                $vectors = $probe->embedSample($profile);
                $measured = EmbeddingDimensionProbe::assertDimensions($profile, $vectors[0]);
            }
        } catch (EmbeddingDimensionMismatch $mismatch) {
            $this->error($mismatch->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error("The embedding probe failed for profile \"{$profile->key}\": {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info("profile: {$profile->key}");
        $this->info("model: {$profile->serviceModel}");
        $this->info("measured dimensions: {$measured}");

        $warning = $languages->warningFor($profile);

        if ($warning !== null) {
            $this->warn("warning: {$warning}");
        }

        if ($this->option('cross-language')) {
            $this->reportCrossLanguage($probe, $profile);
        }

        return match ($mode) {
            'save' => $this->saveReference($reference, $profile, $vectors),
            'compare' => $this->compareReference($reference, $profile, $vectors),
            default => self::SUCCESS,
        };
    }

    private function reportCrossLanguage(EmbeddingDimensionProbe $probe, EmbeddingModelProfile $profile): void
    {
        try {
            $cosines = $probe->crossLanguageCosines($profile);
        } catch (Throwable $exception) {
            $this->warn("warning: the cross-language check could not run: {$exception->getMessage()}");

            return;
        }

        foreach ($cosines as $position => $cosine) {
            $sentence = EmbeddingDimensionProbe::CROSS_LANGUAGE_PAIRS[$position]['it'];

            $this->info('cross-language cosine (it/en): ' . number_format($cosine, 6, '.', '') . " for \"{$sentence}\"");
        }
    }

    /**
     * @param  list<list<float>>  $vectors
     */
    private function saveReference(EmbeddingDriftReference $reference, EmbeddingModelProfile $profile, array $vectors): int
    {
        try {
            $reference->save($profile, $vectors);
        } catch (EmbeddingDriftReferenceException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('reference saved for ' . count($vectors) . ' sample texts');

        return self::SUCCESS;
    }

    /**
     * @param  list<list<float>>  $vectors
     */
    private function compareReference(EmbeddingDriftReference $reference, EmbeddingModelProfile $profile, array $vectors): int
    {
        try {
            $lowest = $reference->lowest($profile, $vectors);
        } catch (EmbeddingDriftReferenceException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $floor = (float) config('ai.features.embeddings.drift_floor', 0.999);

        $this->info('lowest cosine: ' . number_format($lowest['cosine'], 6, '.', '') . " (floor {$floor})");
        $this->info("lowest text: {$lowest['text']}");

        if ($lowest['cosine'] < $floor) {
            $this->error("The vectors of profile \"{$profile->key}\" drifted from the reference: the lowest cosine is below the floor. The server, runtime or precision behind the model changed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
