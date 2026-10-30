<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\AI\Tests\Stubs\EmbeddableTestModel;
use Modules\Core\Models\ModelEmbedding;

const PRUNE_ACTIVE = 'openai_compatible:intfloat/multilingual-e5-small';
const PRUNE_ORPHAN = 'openai_compatible:removed-model';
const PRUNE_OTHER = 'openai_compatible:all-MiniLM-L6-v2';

beforeEach(function (): void {
    $this->seed(AIDatabaseSeeder::class);
    config()->set('ai.features.embeddings.serving_model', PRUNE_ACTIVE);

    Schema::create('embeddable_test_models', function ($table): void {
        $table->id();
        $table->string('title')->nullable();
    });

    $record = new EmbeddableTestModel(['title' => 'A title']);
    $record->saveQuietly();

    foreach ([PRUNE_ACTIVE, PRUNE_ORPHAN, PRUNE_ORPHAN, PRUNE_OTHER] as $key) {
        $record->embeddings()->create(['embedding' => [0.1, 0.2], 'locale' => null, 'model_key' => $key, 'content_hash' => 'h']);
    }
});

function prune_counts(): array
{
    return ModelEmbedding::query()->selectRaw('model_key, count(*) as rows_count')->groupBy('model_key')->orderBy('model_key')
        ->pluck('rows_count', 'model_key')->map(static fn (mixed $count): int => (int) $count)->all();
}

it('deletes the rows of the named model key only', function (): void {
    $this->artisan('ai:embeddings:prune', ['--model-key' => PRUNE_ORPHAN])
        ->expectsOutputToContain('2')
        ->assertSuccessful();

    expect(prune_counts())->toBe([PRUNE_OTHER => 1, PRUNE_ACTIVE => 1]);
});

it('refuses to delete the rows of the active model', function (): void {
    $this->artisan('ai:embeddings:prune', ['--model-key' => PRUNE_ACTIVE])
        ->expectsOutputToContain('active')
        ->assertFailed();

    expect(prune_counts())->toHaveCount(3);
});

it('refuses while a switch is running', function (): void {
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('running', 'embeddings', PRUNE_OTHER, PRUNE_ACTIVE));

    $this->artisan('ai:embeddings:prune', ['--model-key' => PRUNE_ORPHAN])
        ->expectsOutputToContain('running')
        ->assertFailed();

    expect(prune_counts()[PRUNE_ORPHAN])->toBe(2);
});

it('requires a model key', function (): void {
    $this->artisan('ai:embeddings:prune')
        ->expectsOutputToContain('--model-key')
        ->assertFailed();

    expect(prune_counts())->toHaveCount(3);
});
