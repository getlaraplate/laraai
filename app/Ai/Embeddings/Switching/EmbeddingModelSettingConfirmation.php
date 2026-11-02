<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings\Switching;

use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Modules\AI\Ai\Embeddings\EmbeddingLanguageCheck;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Jobs\SwitchEmbeddingModelJob;
use Modules\Core\Contracts\ISettingChangeConfirmation;
use Modules\Core\Data\SettingChangeWarning;
use Modules\Core\Models\Setting;
use Override;

/**
 * The confirmation shown when `features.embeddings.model` is changed on the settings page: what the
 * switch costs and what happens meanwhile. Confirming queues `ai:embeddings:switch --report-failure`,
 * which runs the preflight and, when it refuses, stores the refusal as a failed switch so the field
 * locks and shows why. The field is locked while a
 * switch runs or failed. When the chosen profile declares languages and misses some enabled locales
 * of the installation, a last line says which: a warning, the choice stays possible.
 *
 * Registered once at boot, it resolves the preview when it needs it, so the probe and the
 * embeddable models in use are the container's at that moment.
 */
final readonly class EmbeddingModelSettingConfirmation implements ISettingChangeConfirmation
{
    public const string SETTING = 'features.embeddings.model';

    public function __construct(
        private EmbeddingModelRegistry $registry,
        private EmbeddingSwitchStore $store,
        private EmbeddingLanguageCheck $languages,
    ) {}

    #[Override]
    public function supports(string $settingName): bool
    {
        return $settingName === self::SETTING;
    }

    /**
     * No state is written: Core calls it before the modal and again after the save. The probe
     * latency behind the estimate is cached by the preview, so the second call costs only counts.
     */
    #[Override]
    public function warn(Setting $setting, mixed $newValue): ?SettingChangeWarning
    {
        if (! is_string($newValue) || $newValue === '' || $newValue === $this->registry->activeKey()) {
            return null;
        }

        try {
            $target = $this->registry->get($newValue);
        } catch (InvalidArgumentException) {
            return new SettingChangeWarning('Unknown embedding model', [
                "\"{$newValue}\" is not a configured embedding profile: the switch will refuse it.",
            ]);
        }

        $preview = app(EmbeddingSwitchPreview::class)->for($target);
        $languageWarning = $this->languages->warningFor($target);

        return new SettingChangeWarning('Change the embedding model?', [
            "Current model: {$preview->currentModel} (" . ($preview->currentDimensions ?? 'unknown') . ' dimensions).',
            "Target model: {$preview->targetModel} ({$preview->targetDimensions} dimensions).",
            $preview->dimensionsDiffer
                ? 'The dimensions differ: the index mapping is rebuilt and every record is re-embedded.'
                : 'The dimensions are equal: every record is re-embedded, the index mapping is kept.',
            "{$preview->recordsToEmbed} records to embed, " . self::duration($preview->estimatedSeconds) . '.',
            'Meanwhile vector search is off and search uses keywords only, until the switch ends.',
            'Going back to the previous model is the same procedure and costs the same.',
            ...($languageWarning === null ? [] : [$languageWarning]),
        ]);
    }

    #[Override]
    public function confirmed(Setting $setting, mixed $newValue): void
    {
        if (! is_string($newValue) || $newValue === '') {
            return;
        }

        Artisan::queue('ai:embeddings:switch', ['profile' => $newValue, '--report-failure' => true])
            ->onQueue(SwitchEmbeddingModelJob::QUEUE);
    }

    #[Override]
    public function lockedReason(Setting $setting): ?string
    {
        $state = $this->store->get();

        return match ($state->status) {
            'running' => "An embedding model switch to {$state->target} is running (phase {$state->phase}, " . $state->progressLabel() . '): the model can be changed when it ends.',
            'failed' => "The embedding model switch to {$state->target} failed in phase {$state->phase}: {$state->error}. Resume or abandon it before choosing another model.",
            default => null,
        };
    }

    private static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return 'no time estimate (the probe did not answer)';
        }

        if ($seconds < 60) {
            return "about {$seconds} seconds (an estimate)";
        }

        return 'about ' . (int) ceil($seconds / 60) . ' minutes (an estimate)';
    }
}
