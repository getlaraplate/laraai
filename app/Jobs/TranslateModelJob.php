<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\AI\Services\Translation\TranslationService;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Models\Concerns\HasTranslations;
use Modules\Core\Search\Traits\Searchable;
use Throwable;

final class TranslateModelJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120];

    public int $timeout = 300;

    /**
     * @param  array<string>  $locales
     */
    public function __construct(
        private readonly Model $model,
        private readonly array $locales = [],
        private readonly bool $force = false,
    ) {
        $this->onQueue('translations');
    }

    /**
     * @return array<int, RateLimited>
     */
    public function middleware(): array
    {
        return [
            new RateLimited('translations'),
        ];
    }

    /**
     * @codeCoverageIgnore
     */
    public function handle(TranslationService $translation_service): void
    {
        $model = $this->model->fresh();

        if (! $model instanceof Model) {
            Log::warning('Model not found for translation', [
                'model_class' => $this->model::class,
                'model_id' => $this->model->getKey(),
            ]);

            return;
        }

        if (! $this->isTranslatable($model)) {
            return;
        }

        $default_locale = LocaleContext::getDefault();
        $default_translation = $this->resolveSourceTranslation($model, $default_locale);

        if (! $default_translation instanceof Model) {
            Log::warning('Default translation not found', [
                'model_class' => $model::class,
                'model_id' => $model->getKey(),
            ]);

            return;
        }

        $locales_to_translate = $this->locales === [] ? LocaleContext::getAvailable() : $this->locales;
        $locales_to_translate = array_filter($locales_to_translate, fn (string $locale): bool => $locale !== $default_locale);

        $failure = null;

        foreach ($locales_to_translate as $locale) {
            if (! $this->force && $model->hasTranslation($locale)) {
                continue;
            }

            try {
                $this->translateModel($model, $default_translation, $locale, $translation_service);
            } catch (Exception $e) {
                Log::error('Translation failed for model', [
                    'model_class' => $model::class,
                    'model_id' => $model->getKey(),
                    'locale' => $locale,
                    'error' => $e->getMessage(),
                ]);

                $failure ??= $e;
            }
        }

        // Indexing waits for this event. On failure it is sent by failed(), once the queue
        // gives up, so a retry does not index a half-translated model.
        if ($failure === null && class_uses_trait($model, Searchable::class)) {
            event(new ModelPreProcessingCompleted($model, 'translation'));
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * The queue gave up (all tries spent, or a timeout): let indexing proceed with the
     * translations that were saved, instead of waiting for a completion that never comes.
     */
    public function failed(Throwable $exception): void
    {
        if (class_uses_trait($this->model, Searchable::class)) {
            event(new ModelPreProcessingCompleted($this->model, 'translation'));
        }
    }

    private function resolveSourceTranslation(Model $model, string $default_locale): ?Model
    {
        if (method_exists($model, 'getOriginalTranslation')) {
            $original = $model->getOriginalTranslation();

            if ($original instanceof Model) {
                return $original;
            }
        }

        if (! method_exists($model, 'getTranslation')) {
            return null;
        }

        return $model->getTranslation($default_locale);
    }

    /**
     * @codeCoverageIgnore
     */
    private function translateModel(
        Model $model,
        Model $default_translation,
        string $locale,
        TranslationService $translation_service,
    ): void {
        if (! method_exists($model, 'getTranslatableFields') || ! method_exists($model, 'setTranslation')) {
            return;
        }

        $default_locale = LocaleContext::getDefault();
        $translatable_fields = $model::getTranslatableFields();
        $translated_data = [];

        foreach ($translatable_fields as $field) {
            $value = $default_translation->getAttribute($field);

            if (empty($value)) {
                continue;
            }

            if ($field === 'components' && is_array($value)) {
                $translated_data[$field] = $this->translateComponents(
                    $this->normalizeStringKeyedArray($value),
                    $default_locale,
                    $locale,
                    $translation_service,
                );

                continue;
            }

            if (is_string($value)) {
                $translated_data[$field] = $translation_service->translate($value, $default_locale, $locale);

                continue;
            }

            $translated_data[$field] = $value;
        }

        $model->setTranslation($locale, $translated_data);
    }

    /**
     * @phpstan-assert-if-true ITranslatableModel&Model $model
     */
    private function isTranslatable(Model $model): bool
    {
        return in_array(HasTranslations::class, class_uses_recursive($model), true);
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    private function normalizeStringKeyedArray(array $value): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $item;
        }

        return $normalized;
    }

    /**
     * Translate components JSON recursively.
     *
     * @param  array<string, mixed>  $components
     * @return array<string, mixed>
     *
     * @codeCoverageIgnore
     */
    private function translateComponents(
        array $components,
        string $from_locale,
        string $to_locale,
        TranslationService $translation_service,
    ): array {
        $translated = [];

        foreach ($components as $key => $value) {
            if (is_string($value) && ($value !== '' && $value !== '0')) {
                $translated[$key] = $translation_service->translate($value, $from_locale, $to_locale);
            } elseif (is_array($value)) {
                $translated[$key] = $this->translateComponents(
                    $this->normalizeStringKeyedArray($value),
                    $from_locale,
                    $to_locale,
                    $translation_service,
                );
            } else {
                $translated[$key] = $value;
            }
        }

        return $translated;
    }
}
