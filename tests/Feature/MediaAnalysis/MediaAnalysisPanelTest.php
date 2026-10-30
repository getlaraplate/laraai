<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Modules\AI\Enums\MediaAnalysisStatus;
use Modules\AI\Jobs\AnalyzeMediaJob;
use Modules\AI\Models\MediaAnalysis;
use Modules\Core\Filament\Resources\Media\Pages\ViewMedia;
use Modules\Core\Models\Media;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;

function aiPanelUser(): User
{
    if (! class_exists(App\Models\User::class)) {
        class_alias(User::class, App\Models\User::class);
    }

    /** @var App\Models\User $user */
    $user = App\Models\User::query()->create(User::factory()->raw());
    $user->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));

    return $user;
}

function claimedMediaWithAnalysis(string $hash = 'panel-hash'): Media
{
    $media = new Media();
    $media->forceFill([
        'collection_name' => 'default',
        'name' => 'photo',
        'file_name' => 'photo.jpg',
        'mime_type' => 'image/jpeg',
        'disk' => 'public',
        'size' => 1024,
        'model_type' => User::class,
        'model_id' => 1,
        'custom_properties' => ['content_hash' => $hash],
        'manipulations' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
    $media->saveQuietly();

    MediaAnalysis::factory()->create([
        'content_hash' => $hash,
        'idea' => 'the enduring idea',
        'intent' => 'to inform',
        'entities' => ['lighthouse', 'sea'],
        'analysis_status' => MediaAnalysisStatus::Completed,
        'analysis_model_version' => 'anthropic:claude-sonnet-5',
    ]);

    return $media;
}

beforeEach(function (): void {
    config()->set('ai.features.embeddings.enabled', false);
    Filament::setCurrentPanel('admin');
});

it('shows the AI analysis panel and re-analyze action when the switch is on', function (): void {
    config()->set('ai.features.media_analysis.enabled', true);
    $this->actingAs(aiPanelUser());

    $media = claimedMediaWithAnalysis();

    Livewire::test(ViewMedia::class, ['record' => $media->getKey()])
        ->assertOk()
        ->assertSee('the enduring idea')
        ->assertSee('lighthouse')
        ->assertActionVisible('reanalyzeMedia');
});

it('re-queues the analysis job from the panel action', function (): void {
    config()->set('ai.features.media_analysis.enabled', true);
    Bus::fake([AnalyzeMediaJob::class]);
    $this->actingAs(aiPanelUser());

    $media = claimedMediaWithAnalysis();

    Livewire::test(ViewMedia::class, ['record' => $media->getKey()])
        ->callAction('reanalyzeMedia')
        ->assertHasNoActionErrors();

    Bus::assertDispatched(AnalyzeMediaJob::class);
});

it('hides the AI panel and action when the switch is off', function (): void {
    config()->set('ai.features.media_analysis.enabled', false);
    $this->actingAs(aiPanelUser());

    $media = claimedMediaWithAnalysis();

    Livewire::test(ViewMedia::class, ['record' => $media->getKey()])
        ->assertOk()
        ->assertDontSee('the enduring idea')
        ->assertActionDoesNotExist('reanalyzeMedia');
});
