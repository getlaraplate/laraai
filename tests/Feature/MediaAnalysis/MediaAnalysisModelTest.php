<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Modules\AI\Enums\MediaAnalysisStatus;
use Modules\AI\Models\MediaAnalysis;

uses()->group('db2');

it('casts status to the enum and json columns to arrays', function (): void {
    $analysis = MediaAnalysis::factory()->create([
        'entities' => ['lion', 'elephant'],
        'provenance' => ['idea' => 'llm'],
    ]);

    $fresh = MediaAnalysis::query()->findOrFail($analysis->id);

    expect($fresh->analysis_status)->toBe(MediaAnalysisStatus::Completed)
        ->and($fresh->entities)->toBe(['lion', 'elephant'])
        ->and($fresh->provenance)->toBe(['idea' => 'llm']);
});

it('enforces one analysis row per content hash', function (): void {
    $hash = hash('sha256', 'same-file');

    MediaAnalysis::factory()->create(['content_hash' => $hash]);

    expect(fn () => MediaAnalysis::factory()->create(['content_hash' => $hash]))
        ->toThrow(QueryException::class);
});

it('resolves the same row for the same hash via firstOrCreate', function (): void {
    $hash = hash('sha256', 'shared-file');

    $first = MediaAnalysis::query()->firstOrCreate(['content_hash' => $hash]);
    $second = MediaAnalysis::query()->firstOrCreate(['content_hash' => $hash]);

    expect($second->id)->toBe($first->id)
        ->and(MediaAnalysis::query()->where('content_hash', $hash)->count())->toBe(1);
});

it('hard-deletes so a purged hash can be re-analyzed (soft deletes forced off)', function (): void {
    $hash = hash('sha256', 'purge-me');

    MediaAnalysis::factory()->create(['content_hash' => $hash])->delete();

    expect(MediaAnalysis::query()->where('content_hash', $hash)->count())->toBe(0);

    // The unique index is free again: re-creating for the same hash succeeds.
    $recreated = MediaAnalysis::factory()->create(['content_hash' => $hash]);

    expect($recreated->exists)->toBeTrue();
});

it('builds pending and failed states', function (): void {
    expect(MediaAnalysis::factory()->pending()->create()->analysis_status)->toBe(MediaAnalysisStatus::Pending)
        ->and(MediaAnalysis::factory()->failed()->create()->analysis_status)->toBe(MediaAnalysisStatus::Failed);
});
