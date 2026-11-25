<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use function ai_config_bool;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Contracts\ITranslatableModel;
use Modules\Core\Models\Concerns\HasTranslations;

/**
 * Whether a model is translated automatically: the master switch (the seeded setting
 * `features.translation.enabled`, off), the per-module allowlist, the `HasTranslations` trait and the
 * model's own setting for automatic translation. The listeners that translate on a save and on an
 * approved modification ask here, so an approved modification obeys the same rules as a save.
 */
final readonly class TranslationGate
{
    public function enabled(): bool
    {
        return ai_config_bool('ai.features.translation.enabled', false);
    }

    /**
     * @phpstan-assert-if-true ITranslatableModel&Model $model
     */
    public function allows(Model $model): bool
    {
        return $this->enabled()
            && FeatureModuleGate::allows('translation', $model)
            && $model instanceof ITranslatableModel
            && $model->autoTranslateEnabledBySettings();
    }
}
