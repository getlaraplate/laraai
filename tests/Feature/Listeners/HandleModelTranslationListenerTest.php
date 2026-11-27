<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\AI\Listeners\HandleModelTranslationListener;
use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Events\TranslatedModelSaved;
use Modules\Core\Models\Setting;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Tests\Fixtures\FakeTranslatableModel;

beforeEach(function (): void {
    app(PerModelSettingResolver::class)->flush();
    config(['ai.features.translation.enabled' => true]);
});

it('dispatches translation job when auto translate is enabled for the model', function (): void {
    Bus::fake();

    $model = new FakeTranslatableModel();

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'translations.auto.' . $model->getTable(),
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'translations',
        'description' => 'test',
    ]);

    app(PerModelSettingResolver::class)->flush();

    $event = new TranslatedModelSaved($model);
    (app(HandleModelTranslationListener::class))->handle($event);

    Bus::assertDispatched(TranslateModelJob::class);
    expect($event->isHandled())->toBeTrue();
});

it('does not dispatch translation job when auto translate is disabled for the model', function (): void {
    Bus::fake();

    $model = new FakeTranslatableModel();
    $event = new TranslatedModelSaved($model);

    (app(HandleModelTranslationListener::class))->handle($event);

    Bus::assertNothingDispatched();
    expect($event->isHandled())->toBeFalse();
});

it('dispatches no translation job for a save that is part of a revert (D36)', function (): void {
    Bus::fake();

    $model = new FakeTranslatableModel();

    Setting::factory()->persistedWithoutApprovalCapture()->create([
        'name' => 'translations.auto.' . $model->getTable(),
        'value' => true,
        'type' => SettingTypeEnum::Boolean,
        'group_name' => 'translations',
        'description' => 'test',
    ]);

    app(PerModelSettingResolver::class)->flush();

    $event = new TranslatedModelSaved($model, [], true, fromRevert: true);
    (app(HandleModelTranslationListener::class))->handle($event);

    Bus::assertNothingDispatched();
    expect($event->isHandled())->toBeFalse();
});
