<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Jobs\TranslateModelJob;
use Modules\AI\Services\Translation\TranslationService;
use Modules\Core\Enums\VersionCaptureQuality;
use Modules\Core\Models\Version;
use Modules\Core\Models\VersionSet;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocument;
use Modules\Core\Tests\Stubs\Versioning\TranslatedDocumentTranslation;

/**
 * The machine translation of a record is one revision of its own, reason `ai-translation` and no actor, so it
 * never merges with the revision of whoever wrote the source (D28).
 */
beforeEach(function (): void {
    Config::set('app.locale', 'en');
    config()->set('versionable.version_model', Version::class);

    Schema::create(TranslatedDocument::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('code')->nullable();
        $table->unsignedInteger('lock_version')->default(1);
        $table->timestamps();
    });

    Schema::create(TranslatedDocumentTranslation::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->foreignId('translated_document_id')->constrained(TranslatedDocument::TABLE)->cascadeOnDelete();
        $table->string('locale');
        $table->string('title')->nullable();
        $table->string('body')->nullable();
        $table->timestamps();
    });

    $this->document = TranslatedDocument::query()->create(['code' => 'doc']);
    $this->document->setTranslation('en', ['title' => 'Hello', 'body' => 'World']);
    $this->sets_before = VersionSet::query()->max('id');
});

afterEach(function (): void {
    Schema::dropIfExists(TranslatedDocumentTranslation::TABLE);
    Schema::dropIfExists(TranslatedDocument::TABLE);
});

it('writes every locale of the job in one atomic revision of its own', function (): void {
    $translator = Mockery::mock(TranslationService::class);
    $translator->shouldReceive('translate')->andReturnUsing(static fn (string $text, string $from, string $to): string => "{$text} ({$to})");

    (new TranslateModelJob($this->document, ['it', 'de'], true))->handle($translator);

    $set = VersionSet::query()->where('id', '>', $this->sets_before)->sole();

    expect($set->reason)->toBe('ai-translation')
        ->and($set->user_id)->toBeNull()
        ->and($set->capture)->toBe(VersionCaptureQuality::Atomic)
        ->and($set->versions()->get()->map(static fn (Version $row): string => $row->subject_key['locale'])->sort()->values()->all())->toBe(['de', 'it']);
});

it('keeps the locales that succeeded when another one fails', function (): void {
    $translator = Mockery::mock(TranslationService::class);
    $translator->shouldReceive('translate')->andReturnUsing(static function (string $text, string $from, string $to): string {
        if ($to === 'de') {
            throw new RuntimeException('translation service down');
        }

        return "{$text} ({$to})";
    });

    expect(fn () => (new TranslateModelJob($this->document, ['it', 'de'], true))->handle($translator))->toThrow(RuntimeException::class);

    $set = VersionSet::query()->where('id', '>', $this->sets_before)->sole();

    expect($set->versions()->get()->map(static fn (Version $row): string => $row->subject_key['locale'])->all())->toBe(['it'])
        ->and($this->document->translations()->pluck('locale')->sort()->values()->all())->toBe(['en', 'it']);
});
