<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaTranscriber;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaVisionAnalyzer;
use Modules\AI\Ai\MediaAnalysis\MediaVisionResult;
use Modules\AI\Enums\MediaAnalysisStatus;
use Modules\AI\Jobs\AnalyzeMediaJob;
use Modules\AI\Jobs\GenerateEmbeddingsJob;
use Modules\AI\Models\MediaAnalysis;
use Modules\AI\Services\Media\ImageFingerprint;
use Modules\AI\Tests\Stubs\MediaAnalysis\FakeMediaTranscriber;
use Modules\AI\Tests\Stubs\MediaAnalysis\FakeMediaVisionAnalyzer;
use Modules\AI\Tests\Stubs\MediaAnalysis\GdImages;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Models\Media;
use Modules\Core\Models\User;

const NEAR_DUPLICATE_MODEL_VERSION = 'anthropic:claude-sonnet-5';

/**
 * @param  array<string, mixed>  $customProperties
 */
function nearDuplicateMedia(string $hash, string $bytes, array $customProperties = []): Media
{
    $media = new Media();
    $media->forceFill([
        'collection_name' => 'default',
        'name' => 'm',
        'file_name' => 'm.png',
        'mime_type' => 'image/png',
        'disk' => 'public',
        'size' => mb_strlen($bytes, '8bit'),
        'manipulations' => [],
        'custom_properties' => ['content_hash' => $hash, ...$customProperties],
        'generated_conversions' => [],
        'responsive_images' => [],
        'order_column' => 1,
        'model_type' => User::class,
        'model_id' => 1,
    ]);
    $media->save();

    Storage::disk('public')->put($media->getPathRelativeToRoot(), $bytes);

    return $media;
}

/**
 * An analysis the job could reuse: completed, by the active model, carrying the fingerprint of the image.
 *
 * @param  array<string, mixed>  $attributes
 */
function earlierAnalysis(string $imageBytes, array $attributes = []): MediaAnalysis
{
    return MediaAnalysis::factory()->create([
        'content_hash' => hash('sha256', 'earlier-' . random_int(0, PHP_INT_MAX)),
        'idea' => 'a red door',
        'intent' => 'show the entrance',
        'entities' => ['door'],
        'analysis_model_version' => NEAR_DUPLICATE_MODEL_VERSION,
        'image_fingerprint' => ImageFingerprint::of($imageBytes),
        ...$attributes,
    ]);
}

function analyzeNearDuplicate(Media $media): void
{
    app()->call([new AnalyzeMediaJob($media), 'handle']);
}

beforeEach(function (): void {
    Storage::fake('public');
    Bus::fake([GenerateEmbeddingsJob::class]);
    Event::fake([ModelPreProcessingCompleted::class]);

    // No result => the fake throws when it is called, which proves no model call was made.
    app()->instance(MediaVisionAnalyzer::class, new FakeMediaVisionAnalyzer());
});

it('with distance 0 the job behaves as today', function (): void {
    $original = GdImages::mosaicPng('a');
    earlierAnalysis($original);

    app()->instance(MediaVisionAnalyzer::class, new FakeMediaVisionAnalyzer(
        new MediaVisionResult('A door', ['door'], 'calm', 'inform', null),
    ));

    $hash = hash('sha256', 'resized-a');
    analyzeNearDuplicate(nearDuplicateMedia($hash, GdImages::mosaicPng('a', 120, 100)));

    $analysis = MediaAnalysis::query()->firstWhere('content_hash', $hash);

    expect(config('ai.features.media_analysis.near_duplicate_distance'))->toBe(0)
        ->and($analysis->idea)->toBe('calm')
        ->and($analysis->analysis)->toBeNull()
        ->and($analysis->image_fingerprint)->toBe(ImageFingerprint::of(GdImages::mosaicPng('a', 120, 100)));
});

it('within the distance the earlier description is reused and no model call is made', function (): void {
    config(['ai.features.media_analysis.near_duplicate_distance' => 6]);

    $earlier = earlierAnalysis(GdImages::mosaicPng('a'));
    nearDuplicateMedia($earlier->content_hash, GdImages::mosaicPng('a'), [
        'description' => 'A red door',
        '_provenance' => ['description' => 'llm'],
    ]);

    $hash = hash('sha256', 'resized-a');
    $media = nearDuplicateMedia($hash, GdImages::mosaicPng('a', 120, 100));

    analyzeNearDuplicate($media);

    $analysis = MediaAnalysis::query()->firstWhere('content_hash', $hash);

    expect($analysis->analysis_status)->toBe(MediaAnalysisStatus::Completed)
        ->and($analysis->idea)->toBe('a red door')
        ->and($analysis->intent)->toBe('show the entrance')
        ->and($analysis->entities)->toBe(['door'])
        ->and($analysis->analysis_model_version)->toBe(NEAR_DUPLICATE_MODEL_VERSION)
        ->and($analysis->image_fingerprint)->toBe(ImageFingerprint::of(GdImages::mosaicPng('a', 120, 100)))
        ->and($analysis->analysis['near_duplicate_of'])->toBe($earlier->id)
        ->and($analysis->analysis['near_duplicate_distance'])->toBeInt()->toBeLessThanOrEqual(6);

    $custom = $media->fresh()->custom_properties;
    expect($custom['description'])->toBe('A red door')
        ->and($custom['keywords'])->toBe(['door'])
        ->and($custom['_provenance']['description'])->toBe('llm');

    Bus::assertDispatched(GenerateEmbeddingsJob::class);
    Event::assertDispatched(ModelPreProcessingCompleted::class);
});

it('does not reuse the description an earlier media had from a person', function (): void {
    config(['ai.features.media_analysis.near_duplicate_distance' => 6]);

    $earlier = earlierAnalysis(GdImages::mosaicPng('a'));
    nearDuplicateMedia($earlier->content_hash, GdImages::mosaicPng('a'), ['description' => 'Our front door, 2019']);

    $media = nearDuplicateMedia(hash('sha256', 'resized-a'), GdImages::mosaicPng('a', 120, 100));

    analyzeNearDuplicate($media);

    expect($media->fresh()->custom_properties)->not->toHaveKey('description')
        ->and($media->fresh()->custom_properties['keywords'])->toBe(['door']);
});

it('analyses an image that is farther than the distance', function (): void {
    config(['ai.features.media_analysis.near_duplicate_distance' => 6]);
    earlierAnalysis(GdImages::mosaicPng('a'));

    app()->instance(MediaVisionAnalyzer::class, new FakeMediaVisionAnalyzer(
        new MediaVisionResult('Another picture', ['tree'], 'calm', 'inform', null),
    ));

    $hash = hash('sha256', 'picture-b');
    analyzeNearDuplicate(nearDuplicateMedia($hash, GdImages::mosaicPng('b')));

    $analysis = MediaAnalysis::query()->firstWhere('content_hash', $hash);

    expect($analysis->entities)->toBe(['tree'])
        ->and($analysis->analysis)->toBeNull();
});

it('does not reuse an analysis by another model version or one that is not completed', function (array $attributes): void {
    config(['ai.features.media_analysis.near_duplicate_distance' => 6]);
    earlierAnalysis(GdImages::mosaicPng('a'), $attributes);

    app()->instance(MediaVisionAnalyzer::class, new FakeMediaVisionAnalyzer(
        new MediaVisionResult('Fresh', ['fresh'], 'calm', 'inform', null),
    ));

    $hash = hash('sha256', 'resized-a');
    analyzeNearDuplicate(nearDuplicateMedia($hash, GdImages::mosaicPng('a', 120, 100)));

    expect(MediaAnalysis::query()->firstWhere('content_hash', $hash)->entities)->toBe(['fresh']);
})->with([
    'other model version' => [['analysis_model_version' => 'anthropic:claude-haiku-4']],
    'failed analysis' => [['analysis_status' => MediaAnalysisStatus::Failed->value]],
    'processing analysis' => [['analysis_status' => MediaAnalysisStatus::Processing->value]],
]);

it('keeps the exact content hash lookup first', function (): void {
    config(['ai.features.media_analysis.near_duplicate_distance' => 6]);

    $original = GdImages::mosaicPng('a');
    $exact = earlierAnalysis($original, ['idea' => 'exact']);
    earlierAnalysis($original, ['idea' => 'near']);

    analyzeNearDuplicate(nearDuplicateMedia($exact->content_hash, $original));

    $exact->refresh();

    expect($exact->idea)->toBe('exact')
        ->and($exact->analysis)->toBe([])
        ->and(MediaAnalysis::query()->count())->toBe(2);
});

it('looks only at the newest rows up to the scan limit', function (): void {
    config([
        'ai.features.media_analysis.near_duplicate_distance' => 6,
        'ai.features.media_analysis.near_duplicate_scan_limit' => 1,
    ]);

    earlierAnalysis(GdImages::mosaicPng('a'));
    earlierAnalysis(GdImages::mosaicPng('b'));

    app()->instance(MediaVisionAnalyzer::class, new FakeMediaVisionAnalyzer(
        new MediaVisionResult('Fresh', ['fresh'], 'calm', 'inform', null),
    ));

    $hash = hash('sha256', 'resized-a');
    analyzeNearDuplicate(nearDuplicateMedia($hash, GdImages::mosaicPng('a', 120, 100)));

    expect(MediaAnalysis::query()->firstWhere('content_hash', $hash)->entities)->toBe(['fresh']);
});

it('goes on without a fingerprint when the file is not a decodable image', function (): void {
    config(['ai.features.media_analysis.near_duplicate_distance' => 6]);
    earlierAnalysis(GdImages::mosaicPng('a'));

    app()->instance(MediaVisionAnalyzer::class, new FakeMediaVisionAnalyzer(
        new MediaVisionResult('Fresh', ['fresh'], 'calm', 'inform', null),
    ));

    $hash = hash('sha256', 'not-an-image');
    analyzeNearDuplicate(nearDuplicateMedia($hash, 'these bytes are not a picture'));

    $analysis = MediaAnalysis::query()->firstWhere('content_hash', $hash);

    expect($analysis->analysis_status)->toBe(MediaAnalysisStatus::Completed)
        ->and($analysis->entities)->toBe(['fresh'])
        ->and($analysis->image_fingerprint)->toBeNull();
});

it('does not fingerprint audio', function (): void {
    config(['ai.features.media_analysis.near_duplicate_distance' => 6]);

    $media = nearDuplicateMedia(hash('sha256', 'sound'), GdImages::mosaicPng('a'));
    $media->forceFill(['mime_type' => 'audio/mpeg'])->save();

    app()->instance(MediaTranscriber::class, new FakeMediaTranscriber('hello'));

    analyzeNearDuplicate($media);

    expect(MediaAnalysis::query()->firstWhere('content_hash', hash('sha256', 'sound'))->image_fingerprint)->toBeNull();
});
