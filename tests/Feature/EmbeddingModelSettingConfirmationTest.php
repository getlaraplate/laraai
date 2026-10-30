<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionProbe;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingModelSettingConfirmation;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchState;
use Modules\AI\Ai\Embeddings\Switching\EmbeddingSwitchStore;
use Modules\AI\Contracts\IEmbeddableModels;
use Modules\AI\Database\Seeders\AIDatabaseSeeder;
use Modules\AI\Jobs\SwitchEmbeddingModelJob;
use Modules\Core\Filament\Resources\Settings\Pages\EditSetting;
use Modules\Core\Models\Role;
use Modules\Core\Models\Setting;
use Modules\Core\Models\User;
use Modules\Core\Services\SettingChangeConfirmations;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

const CONFIRM_ACTIVE = 'openai_compatible:intfloat/multilingual-e5-small';
const CONFIRM_SAME_DIMENSIONS = 'openai_compatible:all-MiniLM-L6-v2';
const CONFIRM_OTHER_DIMENSIONS = 'openai_compatible:BAAI/bge-m3';

beforeEach(function (): void {
    config()->set('ai.servers.embeddings.url', 'http://localhost:8000');
    config()->set('ai.features.embeddings.models', [
        CONFIRM_ACTIVE => ['dimensions' => 384],
        CONFIRM_SAME_DIMENSIONS => ['dimensions' => 384],
        CONFIRM_OTHER_DIMENSIONS => ['dimensions' => 1024],
    ]);
    $this->seed(AIDatabaseSeeder::class);
    config()->set('ai.features.embeddings.serving_model', CONFIRM_ACTIVE);

    $provider = Mockery::mock(EmbeddingsProviderInterface::class);
    $provider->shouldReceive('embedText')->andReturn(array_fill(0, 1024, 0.1));
    app()->instance(EmbeddingDimensionProbe::class, new EmbeddingDimensionProbe(
        app(EmbeddingModelRegistry::class),
        static fn (): EmbeddingsProviderInterface => $provider,
    ));

    Http::fake(['*/v1/embeddings' => Http::response(['model' => 'bge-m3', 'data' => [['index' => 0, 'embedding' => ai_test_unit_vector(1024)]]])]);

    $embeddable = Mockery::mock(IEmbeddableModels::class);
    $embeddable->shouldReceive('all')->andReturn([]);
    app()->instance(IEmbeddableModels::class, $embeddable);
});

function embedding_model_setting(): Setting
{
    return Setting::query()->withoutGlobalScopes()->where('name', 'features.embeddings.model')->sole();
}

function embedding_model_confirmation(): EmbeddingModelSettingConfirmation
{
    return app(EmbeddingModelSettingConfirmation::class);
}

function embedding_confirmation_actor(): void
{
    if (! class_exists(App\Models\User::class)) {
        class_alias(User::class, App\Models\User::class);
    }

    /** @var App\Models\User $actor */
    $actor = App\Models\User::query()->create(User::factory()->raw());
    $actor->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));

    test()->actingAs($actor);
    Filament::setCurrentPanel('admin');
}

function embedding_switch_was_queued(?string $profile = null): bool
{
    return Queue::pushed(QueuedCommand::class)->contains(static function (QueuedCommand $job) use ($profile): bool {
        $data = (fn (): array => $this->data)->call($job);

        return $data[0] === 'ai:embeddings:switch'
            && ($data[1]['--report-failure'] ?? false) === true
            && ($profile === null || ($data[1]['profile'] ?? null) === $profile);
    });
}

it('is registered for the embedding model setting only', function (): void {
    $confirmations = app(SettingChangeConfirmations::class);

    expect($confirmations->for('features.embeddings.model'))->toBeInstanceOf(EmbeddingModelSettingConfirmation::class)
        ->and($confirmations->for('search.vector.model'))->toBeNull();
});

it('asks nothing when the chosen profile is the active one', function (): void {
    expect(embedding_model_confirmation()->warn(embedding_model_setting(), CONFIRM_ACTIVE))->toBeNull();
});

it('shows both models, both dimensions, the work and what happens meanwhile', function (): void {
    $warning = embedding_model_confirmation()->warn(embedding_model_setting(), CONFIRM_OTHER_DIMENSIONS);
    $text = implode("\n", $warning->lines);

    expect($warning->title)->not->toBe('')
        ->and($text)->toContain(CONFIRM_ACTIVE)
        ->and($text)->toContain(CONFIRM_OTHER_DIMENSIONS)
        ->and($text)->toContain('384')
        ->and($text)->toContain('1024')
        ->and($text)->toContain('index mapping is rebuilt')
        ->and($text)->toContain('0 records')
        ->and($text)->toContain('estimate')
        ->and($text)->toContain('keywords only')
        ->and($text)->toContain('Going back');
});

it('says a change with equal dimensions only re-embeds', function (): void {
    $text = implode("\n", embedding_model_confirmation()->warn(embedding_model_setting(), CONFIRM_SAME_DIMENSIONS)->lines);

    expect($text)->toContain('re-embedded')
        ->and($text)->not->toContain('index mapping is rebuilt');
});

it('locks the field with the phase while a switch runs or failed', function (): void {
    $confirmation = embedding_model_confirmation();
    $store = app(EmbeddingSwitchStore::class);

    expect($confirmation->lockedReason(embedding_model_setting()))->toBeNull();

    $store->put(new EmbeddingSwitchState('running', 'embeddings', CONFIRM_OTHER_DIMENSIONS, CONFIRM_ACTIVE, 40, 12));
    expect($confirmation->lockedReason(embedding_model_setting()))->toContain('embeddings')->toContain('12/40');

    $store->put(new EmbeddingSwitchState('failed', 'indexes', CONFIRM_OTHER_DIMENSIONS, CONFIRM_ACTIVE, 40, 40, 'mapping refused'));
    expect($confirmation->lockedReason(embedding_model_setting()))->toContain('indexes')->toContain('mapping refused');
});

it('still warns, with no estimate and after a short timeout, when the service hangs', function (): void {
    $timeouts = [];
    Http::fake(['*/v1/embeddings' => static function ($request, array $options) use (&$timeouts): never {
        $timeouts[] = $options['timeout'] ?? null;

        throw new ConnectionException('timed out');
    }]);

    $warning = embedding_model_confirmation()->warn(embedding_model_setting(), CONFIRM_OTHER_DIMENSIONS);

    expect(implode("\n", $warning->lines))->toContain('no time estimate')
        ->and($timeouts)->toBe([3]);
});

it('shows the refusal of a start reported by the queued command in the lock text', function (): void {
    app(EmbeddingSwitchStore::class)->put(new EmbeddingSwitchState('failed', 'preflight', CONFIRM_OTHER_DIMENSIONS, CONFIRM_ACTIVE, error: 'the embedding service does not answer'));

    expect(embedding_model_confirmation()->lockedReason(embedding_model_setting()))
        ->toContain('preflight')
        ->toContain('the embedding service does not answer');
});

it('queues ai:embeddings:switch with the confirmed profile', function (): void {
    Queue::fake();

    embedding_model_confirmation()->confirmed(embedding_model_setting(), CONFIRM_OTHER_DIMENSIONS);

    expect(embedding_switch_was_queued(CONFIRM_OTHER_DIMENSIONS))->toBeTrue();
    Queue::assertPushedOn(SwitchEmbeddingModelJob::QUEUE, QueuedCommand::class);
});

it('changes nothing when the confirmation is cancelled', function (): void {
    embedding_confirmation_actor();
    Queue::fake();
    $setting = embedding_model_setting();

    Livewire::test(EditSetting::class, ['record' => $setting->getKey()])
        ->fillForm(['value' => CONFIRM_OTHER_DIMENSIONS])
        ->call('save')
        ->assertActionMounted('confirmSettingChange')
        ->assertMountedActionModalSee(['1024', '384'])
        ->unmountAction();

    expect($setting->fresh()->value)->toBe(CONFIRM_ACTIVE)
        ->and(embedding_switch_was_queued())->toBeFalse()
        ->and(app(EmbeddingSwitchStore::class)->get()->status)->toBe('idle');
});

it('saves the target and starts the switch when the change is confirmed', function (): void {
    embedding_confirmation_actor();
    Queue::fake();
    $setting = embedding_model_setting();

    Livewire::test(EditSetting::class, ['record' => $setting->getKey()])
        ->fillForm(['value' => CONFIRM_OTHER_DIMENSIONS])
        ->call('save')
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($setting->fresh()->value)->toBe(CONFIRM_OTHER_DIMENSIONS)
        ->and(embedding_switch_was_queued(CONFIRM_OTHER_DIMENSIONS))->toBeTrue();
});
