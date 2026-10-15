<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\AI\Contracts\ITranslatableModelClassNames;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\AI\Services\Translation\TranslationService;
use Modules\Core\Helpers\LocaleContext;
use Override;
use Throwable;

final class TranslateMissingCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    #[Override]
    protected $signature = 'model:translate-missing
                            {model : The model to translate}
                            {--locale= : Specific locale to check for missing translations}
                            {--sync : Run synchronously instead of queued}';

    /**
     * The console command description.
     */
    #[Override]
    protected $description = 'Find and translate models with missing translations <fg=magenta>(✨ Modules\AI)</fg=magenta>';

    public function __construct(private readonly ITranslatableModelClassNames $translatable_model_class_names)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @codeCoverageIgnore Depends on Core module (LocaleContext)
     */
    public function handle(): int
    {
        $model_type = $this->argument('model');
        $locale = $this->option('locale');
        $sync = $this->option('sync');

        $default_locale = LocaleContext::getDefault();
        $locales_to_check = $locale ? [$locale] : array_filter(
            LocaleContext::getAvailable(),
            fn (string $l): bool => $l !== $default_locale,
        );

        $translatable_models = $this->translatable_model_class_names->all();

        if (! Str::contains($model_type, '\\')) {
            $model_type = '\\' . $model_type;
        }
        $model_class = array_filter($translatable_models, fn (string $model): bool => Str::endsWith($model, $model_type));

        if ($model_class === []) {
            $this->error("Invalid model type: {$model_type}. Not found or not translatable");

            return Command::FAILURE;
        }

        if (count($model_class) > 1) {
            $this->error('Multiple models found: ' . implode(', ', $model_class));

            return Command::FAILURE;
        }

        $model_class = head($model_class);

        if (! is_string($model_class) || $model_class === '') {
            $this->error("Invalid model type: {$model_type}. Not found or not translatable");

            return Command::FAILURE;
        }

        $this->info('Finding models with missing translations...');

        $models_to_translate = [];
        $key_name = $model_class::query()->getModel()->getKeyName();

        foreach ($locales_to_check as $check_locale) {
            $query = $model_class::query()
                ->whereHas('translations', function (\Illuminate\Database\Eloquent\Builder $q) use ($default_locale): void {
                    $q->whereRaw('locale = ?', [$default_locale]);
                })
                ->whereDoesntHave('translations', function (\Illuminate\Database\Eloquent\Builder $q) use ($check_locale): void {
                    $q->whereRaw('locale = ?', [$check_locale]);
                });

            foreach ($query->lazyById(100, $key_name) as $model) {
                if (! isset($models_to_translate[$model->id])) {
                    $models_to_translate[$model->id] = [
                        'model' => $model,
                        'locales' => [],
                    ];
                }

                $models_to_translate[$model->id]['locales'][] = $check_locale;
            }
        }

        $count = count($models_to_translate);

        if ($count === 0) {
            $this->info('No models with missing translations found');

            return Command::SUCCESS;
        }

        $this->info("Found {$count} model(s) with missing translations");

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($models_to_translate as $data) {
            $model = $data['model'];
            $locales = $data['locales'];

            if ($sync) {
                $job = new TranslateModelJob($model, $locales, false);

                try {
                    $job->handle(resolve(TranslationService::class));
                } catch (Throwable $exception) {
                    // Synchronous runs have no queue to retry: report, let indexing proceed, move on.
                    report($exception);
                    $job->failed($exception);
                    $this->newLine();
                    $this->warn("Translation failed for {$model->getKey()}: {$exception->getMessage()}");
                }
            } else {
                dispatch(new TranslateModelJob($model, $locales, false));
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Translation job(s) dispatched for {$count} model(s)");

        return Command::SUCCESS;
    }
}
