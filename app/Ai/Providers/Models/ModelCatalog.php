<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers\Models;

use Modules\AI\Ai\Providers\AiModelChoice;
use Modules\AI\Enums\AiModelFeature;
use Modules\AI\Enums\ProviderListingStatus;
use Throwable;

/**
 * Builds the model choices of a set of features. Each needed provider is called once per
 * build. A provider that is not configured loses its entries; one that fails (any error,
 * including an answer that is not a model list) keeps the entries it had, so an outage never
 * empties a list and one provider cannot abort the others.
 */
final readonly class ModelCatalog
{
    public function __construct(private ProviderConfiguration $providers) {}

    /**
     * @param  list<AiModelFeature>  $features
     * @param  array<string, list<string>>  $currentChoices  keyed by setting name
     */
    public function build(array $features, array $currentChoices): CatalogResult
    {
        $outcomes = [];
        $listed = [];

        foreach ($features as $feature) {
            foreach ($feature->supportedProviders() as $provider) {
                if (! array_key_exists($provider, $outcomes)) {
                    [$outcomes[$provider], $listed[$provider]] = $this->listProvider($provider);
                }
            }
        }

        $choices = [];

        foreach ($features as $feature) {
            $choices[$feature->settingName()] = $this->choicesFor(
                $feature,
                $outcomes,
                $listed,
                $currentChoices[$feature->settingName()] ?? [],
            );
        }

        return new CatalogResult($choices, $outcomes);
    }

    /**
     * @return array{0: ProviderOutcome, 1: list<ListedModel>|null}
     */
    private function listProvider(string $provider): array
    {
        if (! $this->providers->isConfigured($provider)) {
            return [new ProviderOutcome($provider, ProviderListingStatus::NotConfigured), null];
        }

        $lister = $this->providers->lister($provider);

        if ($lister === null) {
            return [new ProviderOutcome($provider, ProviderListingStatus::Listed, 1), null];
        }

        try {
            $models = $lister->list();
        } catch (Throwable $exception) {
            return [new ProviderOutcome($provider, ProviderListingStatus::Failed, error: $exception->getMessage()), null];
        }

        return [new ProviderOutcome($provider, ProviderListingStatus::Listed, count($models)), $models];
    }

    /**
     * @param  array<string, ProviderOutcome>  $outcomes
     * @param  array<string, list<ListedModel>|null>  $listed
     * @param  list<string>  $current
     * @return list<string>
     */
    private function choicesFor(AiModelFeature $feature, array $outcomes, array $listed, array $current): array
    {
        $entries = [];

        foreach ($feature->supportedProviders() as $provider) {
            $outcome = $outcomes[$provider];

            if ($outcome->status === ProviderListingStatus::NotConfigured) {
                continue;
            }

            if ($outcome->status === ProviderListingStatus::Failed) {
                foreach ($current as $entry) {
                    if ($provider === AiModelChoice::parse($entry)->provider) {
                        $entries[] = $entry;
                    }
                }

                continue;
            }

            $models = $listed[$provider];

            if ($models === null) {
                $entries[] = $provider;

                continue;
            }

            foreach ($models as $model) {
                if ($model->satisfies($feature->requiredCapabilities())) {
                    $entries[] = new AiModelChoice($provider, $model->id)->value();
                }
            }
        }

        $entries = array_values(array_unique($entries));
        sort($entries, SORT_STRING);

        // Offered first, and whatever the providers answer: working without a model needs no provider.
        return $feature->allowsNone() ? [AiModelFeature::NONE, ...$entries] : $entries;
    }
}
