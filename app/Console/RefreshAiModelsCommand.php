<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Modules\AI\Ai\Providers\Models\CatalogResult;
use Modules\AI\Ai\Providers\Models\ModelCatalog;
use Modules\AI\Enums\AiModelFeature;
use Modules\AI\Enums\ProviderListingStatus;
use Modules\Core\Models\Setting;
use Override;

/**
 * Refreshes the choices of the AI model settings from the providers' model lists. Run from
 * the settings grid (one setting) and nightly (all of them). Exits 1 when a provider failed:
 * its previous entries are kept, the other providers' entries are still written.
 */
final class RefreshAiModelsCommand extends Command
{
    #[Override]
    protected $signature = 'ai:models:refresh
                            {--setting= : Refresh only this model setting, e.g. features.chat.model}';

    #[Override]
    protected $description = 'Refresh the model choices of the AI model settings from the providers <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function handle(ModelCatalog $catalog): int
    {
        $features = $this->features();

        if ($features === null) {
            $this->error('Not an AI model setting: ' . $this->option('setting'));

            return self::FAILURE;
        }

        $names = array_map(static fn (AiModelFeature $feature): string => $feature->settingName(), $features);
        $settings = Setting::query()->whereIn('name', $names)->get()->keyBy('name');
        $current = $settings
            ->map(static fn (Setting $setting): array => array_values(array_filter((array) $setting->choices, 'is_string')))
            ->all();

        $result = $catalog->build($features, $current);

        $this->reportProviders($result);

        foreach ($features as $feature) {
            $this->writeChoices($feature, $settings->get($feature->settingName()), $result);
        }

        return $result->hasFailures() ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<AiModelFeature>|null
     */
    private function features(): ?array
    {
        $name = $this->option('setting');

        if (! is_string($name) || $name === '') {
            return AiModelFeature::cases();
        }

        $feature = AiModelFeature::fromSettingName($name);

        return $feature === null ? null : [$feature];
    }

    private function reportProviders(CatalogResult $result): void
    {
        foreach ($result->outcomes as $outcome) {
            match ($outcome->status) {
                ProviderListingStatus::Listed => $this->line("{$outcome->provider}: {$outcome->modelCount} models"),
                ProviderListingStatus::NotConfigured => $this->line("{$outcome->provider}: not configured"),
                ProviderListingStatus::Failed => $this->error("{$outcome->provider}: failed, previous entries kept ({$outcome->error})"),
            };
        }
    }

    private function writeChoices(AiModelFeature $feature, ?Setting $setting, CatalogResult $result): void
    {
        $name = $feature->settingName();

        if (! $setting instanceof Setting) {
            $this->warn("{$name}: setting not seeded, skipped");

            return;
        }

        $choices = $result->choices[$name];
        $setting->choices = $choices;
        $setting->save();

        $this->line("{$name}: " . count($choices) . ' choices');

        if ($choices === []) {
            $this->warn("{$name}: no configured provider offers a model");
        } elseif ($setting->isValueOutsideChoices()) {
            $this->warn("{$name}: current value " . json_encode($setting->value) . ' is no longer offered');
        }
    }
}
