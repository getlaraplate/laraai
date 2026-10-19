<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs;

use Closure;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\EngineManager;
use Mockery;
use Mockery\MockInterface;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchOrchestrator;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\AI\Contracts\IEmbeddingService;
use Modules\AI\Contracts\IRagIndexRebuilder;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Models\Setting;
use Modules\Core\Search\Contracts\ITextEmbedder;
use NeuronAI\RAG\Document;
use RuntimeException;

/**
 * Sets up a small corpus and fakes for driving an embedding model switch end to end: the provider
 * answers with vectors of the length of the profile in force, the search engine is
 * {@see RecordingSwitchSearchEngine} and the RAG rebuilder is a recording mock planning
 * {@see self::$ragPlan}. Queue jobs run on the
 * sync connection the test suite uses.
 */
final class EmbeddingSwitchHarness
{
    public const string ACTIVE = 'sentence_transformers:intfloat/multilingual-e5-small';

    public const string TARGET = 'sentence_transformers:all-MiniLM-L6-v2';

    public const string WIDE = 'sentence_transformers:wide-768';

    /**
     * Texts the fake provider refuses to embed.
     *
     * @var list<string>
     */
    public static array $refusedTexts = [];

    /**
     * The profile keys in force for each batch the fake provider embedded.
     *
     * @var list<string>
     */
    public static array $embeddedWith = [];

    /**
     * The targets the RAG indexes were prepared (recreated) for.
     *
     * @var list<array{target: string, dimensions: int}>
     */
    public static array $ragRebuilds = [];

    /**
     * The documentation chunks the fake RAG rebuilder plans; none unless a test sets them.
     *
     * @var array<string, array{model: string, from: int|string|null, to: int|string|null}>
     */
    public static array $ragPlan = [];

    /**
     * The documentation chunks the fake RAG rebuilder wrote, with the target.
     *
     * @var list<array{target: string, model: string, from: int|string|null, to: int|string|null}>
     */
    public static array $ragWrites = [];

    /**
     * Documentation chunk models (`rag:<profile>`) the fake RAG rebuilder fails to write.
     *
     * @var list<string>
     */
    public static array $refusedRagChunks = [];

    /**
     * Configures the profiles, the Core vector settings, the corpus table and every fake; returns the engine.
     */
    public static function boot(): RecordingSwitchSearchEngine
    {
        self::$refusedTexts = [];
        self::$embeddedWith = [];
        self::$ragRebuilds = [];
        self::$ragPlan = [];
        self::$ragWrites = [];
        self::$refusedRagChunks = [];

        config()->set('ai.features.embeddings.models.' . self::WIDE, ['dimensions' => 768]);
        config()->set('core.search.vector.enabled', true);
        config()->set('core.search.vector.dimensions', 384);
        config()->set('core.search.vector.similarity', 'cosine');
        config()->set('core.search.vector.model', self::ACTIVE);
        config()->set('core.search.vector.suspended_reason', null);

        self::seedVectorSettings();

        Schema::create('embeddable_test_models', static function ($table): void {
            $table->id();
            $table->string('title')->nullable();
        });

        $resolver = Mockery::mock(IEmbeddableModels::class);
        $resolver->shouldReceive('all')->andReturn([EmbeddableTestModel::class]);
        app()->instance(IEmbeddableModels::class, $resolver);

        app()->instance(IEmbeddingService::class, self::embeddingService());
        app()->instance(ITextEmbedder::class, new class implements ITextEmbedder
        {
            public function embed(string $text): array
            {
                return array_fill(0, app(EmbeddingModelRegistry::class)->active()->dimensions, 0.3);
            }
        });

        /** @var IRagIndexRebuilder&MockInterface $rag */
        $rag = Mockery::mock(IRagIndexRebuilder::class);
        $rag->shouldReceive('prepare')->andReturnUsing(static function (EmbeddingModelProfile $target): void {
            self::$ragRebuilds[] = ['target' => $target->key, 'dimensions' => $target->dimensions];
        });
        $rag->shouldReceive('plan')->andReturnUsing(static fn (): array => self::$ragPlan);
        $rag->shouldReceive('writeChunk')->andReturnUsing(static function (EmbeddingModelProfile $target, array $chunk): void {
            if (in_array($chunk['model'], self::$refusedRagChunks, true)) {
                throw new RuntimeException("refused to write {$chunk['model']}");
            }

            self::$ragWrites[] = ['target' => $target->key, ...$chunk];
        });
        app()->instance(IRagIndexRebuilder::class, $rag);

        $engine = new RecordingSwitchSearchEngine(384);
        app(EngineManager::class)->extend('switch-recording', static fn (): RecordingSwitchSearchEngine => $engine);
        app(EngineManager::class)->forgetDrivers();
        config()->set('scout.driver', 'switch-recording');

        return $engine;
    }

    /**
     * Records with the given titles, each embedded with the active profile and indexed with its vectors.
     *
     * @return list<EmbeddableTestModel>
     */
    public static function corpus(RecordingSwitchSearchEngine $engine, string ...$titles): array
    {
        $records = [];

        foreach ($titles as $title) {
            $record = new EmbeddableTestModel(['title' => $title]);
            $record->saveQuietly();
            new GenerateEmbeddingsJob($record)->handle(app(IEmbeddingService::class));
            $records[] = $record;
        }

        $engine->update(collect($records));

        return $records;
    }

    /**
     * Stores a started switch, as `ai:embeddings:switch` does after its preflight.
     */
    public static function start(string $target): void
    {
        $store = app(EmbeddingSwitchStore::class);
        $store->put(new EmbeddingSwitchState('running', 'preflight', $target, self::ACTIVE, startedAt: now()->toIso8601String()));
        $store->suspend();
    }

    /**
     * Advances until the switch stops running or reaches `$phase`.
     */
    public static function advanceUntil(?string $phase = null, int $steps = 30): EmbeddingSwitchState
    {
        $orchestrator = app(EmbeddingSwitchOrchestrator::class);
        $state = app(EmbeddingSwitchStore::class)->get();

        while ($steps-- > 0 && $orchestrator->canAdvance($state) && ($phase === null || $state->phase !== $phase)) {
            $state = $orchestrator->advance();
        }

        return $state;
    }

    /**
     * The component value of every vector the fake provider produces for a profile, so a document
     * shows which model's vectors it carries.
     */
    public static function vectorValue(string $modelKey): float
    {
        return match ($modelKey) {
            self::ACTIVE => 0.2,
            self::TARGET => 0.4,
            self::WIDE => 0.6,
            default => 0.1,
        };
    }

    public static function setting(string $name): mixed
    {
        return Setting::query()->withoutGlobalScopes()->where('name', $name)->value('value');
    }

    public static function settingExists(string $name): bool
    {
        return Setting::query()->withoutGlobalScopes()->where('name', $name)->exists();
    }

    /**
     * @return list<string|null>
     */
    public static function storedModelKeys(): array
    {
        return ModelEmbedding::query()->distinct()->orderBy('model_key')->pluck('model_key')->all();
    }

    public static function rowsOf(string $modelKey): int
    {
        return ModelEmbedding::query()->where('model_key', $modelKey)->count();
    }

    /**
     * Runs the callback with the given texts refused by the fake provider.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function refusing(array $texts, Closure $callback): mixed
    {
        self::$refusedTexts = $texts;

        try {
            return $callback();
        } finally {
            self::$refusedTexts = [];
        }
    }

    /**
     * The Core settings the activation writes, seeded as the Core seeder does (managed; the
     * suspended reason as a JSON null, since `core_settings.value` is NOT NULL).
     */
    private static function seedVectorSettings(): void
    {
        $settings = [
            'search.vector.dimensions' => ['integer', 384],
            'search.vector.similarity' => ['string', 'cosine'],
            'search.vector.model' => ['string', self::ACTIVE],
            'search.vector.suspended_reason' => ['string', null],
        ];

        foreach ($settings as $name => [$type, $value]) {
            $setting = Setting::factory()->persistedWithoutApprovalCapture()->create([
                'name' => $name,
                'module' => 'Core',
                'type' => $type,
                'value' => 'seed',
                'choices' => null,
                'group_name' => 'search',
            ]);

            Setting::query()->withoutGlobalScopes()->whereKey($setting->getKey())->toBase()
                ->update(['value' => json_encode($value, JSON_THROW_ON_ERROR), 'managed' => true]);
        }

        // The factory's save pushed its placeholder into the runtime config.
        app(EmbeddingSwitchStore::class)->resync(array_keys($settings));
    }

    /**
     * A provider fake answering with vectors of the length of the profile in force.
     */
    private static function embeddingService(): IEmbeddingService
    {
        $service = Mockery::mock(IEmbeddingService::class);
        $service->shouldReceive('embedDocumentsBatch')->andReturnUsing(static function (array $texts): array {
            $profile = app(EmbeddingModelRegistry::class)->active();
            self::$embeddedWith[] = $profile->key;

            return array_map(static function (string $text) use ($profile): array {
                if (in_array($text, self::$refusedTexts, true)) {
                    throw new RuntimeException("refused to embed {$text}");
                }

                $document = (new Document($text))
                    ->setEmbedding(array_fill(0, $profile->dimensions, self::vectorValue($profile->key)));

                return [$document];
            }, $texts);
        });

        return $service;
    }
}
