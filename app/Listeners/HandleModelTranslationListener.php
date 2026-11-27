<?php

declare(strict_types=1);

namespace Modules\AI\Listeners;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\AI\Services\TranslationGate;
use Modules\Core\Events\ModelRequiresIndexing;
use Modules\Core\Events\TranslatedModelSaved;
use Modules\Core\Search\Traits\Searchable;

final readonly class HandleModelTranslationListener
{
    public function __construct(private TranslationGate $gate) {}

    public function handle(TranslatedModelSaved $event): void
    {
        // A revert puts the translations back as they were: translating them again would overwrite them (D36).
        if ($event->fromRevert || ! $this->shouldHandle($event->model)) {
            return;
        }

        if (class_uses_trait($event->model, Searchable::class)) {
            $this->registerTranslationForIndexing($event->model);
        }

        dispatch(new TranslateModelJob($event->model, $event->locales, $event->force));
        $event->markAsHandled();
    }

    private function shouldHandle(Model $model): bool
    {
        return $this->gate->allows($model);
    }

    private function registerTranslationForIndexing(Model $model): void
    {
        $model_key = $model->getKey();

        if (! is_int($model_key) && ! is_string($model_key)) {
            return;
        }

        $cache_key = ModelRequiresIndexing::cacheKey($model);
        $indexing_event = Cache::get($cache_key);

        if ($indexing_event instanceof ModelRequiresIndexing) {
            $indexing_event->addRequiredPreProcessing('translation');
            Cache::put($cache_key, $indexing_event, now()->addMinutes(ModelRequiresIndexing::CACHE_TTL_MINUTES));
        }
    }
}
