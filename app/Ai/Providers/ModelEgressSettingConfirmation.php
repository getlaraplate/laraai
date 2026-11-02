<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers;

use Modules\AI\Enums\AiModelFeature;
use Modules\Core\Contracts\ISettingChangeConfirmation;
use Modules\Core\Data\SettingChangeWarning;
use Modules\Core\Models\Setting;
use Override;

/**
 * The confirmation shown when the model of an {@see AiModelFeature} is changed on the settings page
 * to one whose provider is an external service: the text the feature sends will leave the
 * installation. A model on a server the installation declares, or one whose provider cannot be told,
 * is saved without a modal. Nothing runs on confirm and the field is never locked.
 */
final readonly class ModelEgressSettingConfirmation implements ISettingChangeConfirmation
{
    public function __construct(
        private RoleEgress $egress,
    ) {}

    #[Override]
    public function supports(string $settingName): bool
    {
        return AiModelFeature::fromSettingName($settingName) instanceof AiModelFeature;
    }

    #[Override]
    public function warn(Setting $setting, mixed $newValue): ?SettingChangeWarning
    {
        if (! is_string($newValue) || $this->egress->forChoice($newValue) !== true) {
            return null;
        }

        return new SettingChangeWarning(__('ai.messages.egressTitle'), [RoleEgress::describe(true)]);
    }

    #[Override]
    public function confirmed(Setting $setting, mixed $newValue): void {}

    #[Override]
    public function lockedReason(Setting $setting): ?string
    {
        return null;
    }
}
