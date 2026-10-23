<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers\Models;

use Override;

/**
 * The models an installer declared in configuration, for a provider whose server has no catalogue to ask
 * (the transcription server of the `openai_compatible` provider): `ai.{key}` is a list of model names.
 * Makes no request, so a refresh never waits on it.
 */
final readonly class ConfiguredModelLister implements ModelLister
{
    public function __construct(private string $key) {}

    /**
     * @return list<ListedModel>
     */
    #[Override]
    public function list(): array
    {
        $models = [];

        foreach ((array) config('ai.' . $this->key, []) as $model) {
            if (is_string($model) && $model !== '') {
                $models[] = new ListedModel($model, null);
            }
        }

        return $models;
    }
}
