<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers;

use function ai_config_string;

use InvalidArgumentException;
use Modules\AI\Enums\AiModelFeature;

/**
 * One provider and model, stored in Settings as `provider:model`, or `provider` alone for
 * providers without a model catalogue (`deepl`). The value splits on the first
 * colon because Ollama ids contain one (`llama3.2:3b`) and provider names never do.
 */
final readonly class AiModelChoice
{
    public function __construct(
        public string $provider,
        public ?string $model = null,
    ) {}

    public static function parse(string $value): self
    {
        $value = mb_trim($value);

        throw_if($value === '', InvalidArgumentException::class, 'An AI model choice cannot be empty');

        $separator = mb_strpos($value, ':');

        if ($separator === false) {
            return new self($value);
        }

        $model = mb_substr($value, $separator + 1);

        return new self(mb_substr($value, 0, $separator), $model === '' ? null : $model);
    }

    /**
     * The overlay writes `ai.{setting name}` when the setting row exists; until then the
     * feature's default choice applies.
     */
    public static function forFeature(AiModelFeature $feature): self
    {
        return self::parse(ai_config_string('ai.' . $feature->settingName(), $feature->defaultChoice()));
    }

    public function value(): string
    {
        return $this->model === null ? $this->provider : $this->provider . ':' . $this->model;
    }
}
