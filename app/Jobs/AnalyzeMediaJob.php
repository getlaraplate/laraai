<?php

declare(strict_types=1);

namespace Modules\AI\Jobs;

use function ai_config_int;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaTranscriber;
use Modules\AI\Ai\MediaAnalysis\Contracts\MediaVisionAnalyzer;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelRegistry;
use Modules\AI\Ai\MediaAnalysis\MediaVisionResult;
use Modules\AI\Enums\MediaAnalysisStatus;
use Modules\AI\Models\MediaAnalysis;
use Modules\AI\Services\Media\ImageFingerprint;
use Modules\Core\Events\ModelPreProcessingCompleted;
use Modules\Core\Models\Media;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

/**
 * Async AI analysis of a claimed media (M6, M11, M15, M20). Per-mime: image →
 * vision (caption/entities/idea/intent/OCR); audio/video → transcription; PDF →
 * extracted text. The analysis is keyed by the file `content_hash` and reused
 * when a fresh row already exists (lookup-before-work, M15). Results are written
 * to the AI-owned {@see MediaAnalysis} row; empty Core display fields are filled
 * (M3c); embeddings are chained so the media vector includes the AI text (M11);
 * and {@see ModelPreProcessingCompleted} is emitted so Core finalizes indexing.
 * An analyzer error fails the attempt so the job retries; once retries are spent
 * it degrades (M12): the row is marked failed and completion is still emitted so
 * the deterministic layer indexes.
 *
 * Opt-in near-duplicate reuse (`features.media_analysis.near_duplicate_distance`, off): after the exact
 * lookup misses, an image whose perceptual fingerprint is within that distance of an earlier completed
 * analysis by the same model copies that analysis instead of calling the model. The fingerprint is
 * stored for every analysed image either way, so the feature can be switched on later.
 */
final class AnalyzeMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 60, 120];

    public int $timeout = 300;

    public function __construct(private readonly Media $media)
    {
        $this->onQueue('media_analysis');
    }

    public function handle(
        MediaVisionAnalyzer $vision,
        MediaTranscriber $transcriber,
        MediaAnalysisModelRegistry $registry,
    ): void {
        $media = $this->media->fresh() ?? $this->media;

        $hash = $this->contentHash($media);

        if ($hash === null) {
            event(new ModelPreProcessingCompleted($this->media, 'media_analysis'));

            return;
        }

        $profile = $registry->active('vision');
        $modelVersion = $profile->key;

        $existing = MediaAnalysis::query()->firstWhere('content_hash', $hash);

        // Lookup-before-work (M15): a fresh analysis for this file already exists.
        if ($existing instanceof MediaAnalysis
            && $existing->analysis_status === MediaAnalysisStatus::Completed
            && $existing->analysis_model_version === $modelVersion) {
            $this->fillCoreFields($media, null, $existing->entities ?? []);
            dispatch(new GenerateEmbeddingsJob($media));
            $media->reindexOwner();
            event(new ModelPreProcessingCompleted($this->media, 'media_analysis'));

            return;
        }

        $mime = (string) $media->mime_type;
        $type = mb_strtolower((string) strtok($mime, '/'));
        $fingerprint = $type === 'image' ? $this->fingerprint($media) : null;

        $near = $fingerprint === null ? null : $this->nearDuplicate($fingerprint, $hash, $modelVersion);

        if ($near instanceof MediaAnalysis) {
            $this->reuseNearDuplicate($media, $hash, $fingerprint, $near);

            return;
        }

        $analysis = MediaAnalysis::query()->firstOrNew(['content_hash' => $hash]);
        $analysis->analysis_status = MediaAnalysisStatus::Processing;
        $analysis->image_fingerprint = $fingerprint;
        $analysis->save();

        $caption = null;
        $provenance = [];

        if ($type === 'image') {
            $result = $vision->analyze($media->getPath(), $mime, $profile);
            $caption = $result->caption;
            $analysis->entities = $result->entities;
            $analysis->idea = $result->idea;
            $analysis->intent = $result->intent;
            $analysis->ocr_text = $result->ocrText;
            $provenance = $this->producedFrom($result);
        } elseif ($type === 'audio' || $type === 'video') {
            $transcript = $transcriber->transcribe($media->getPath(), $mime, $profile);
            $analysis->transcript = $transcript;

            if ($transcript !== null) {
                $provenance['transcript'] = 'llm';
            }
        } elseif ($mime === 'application/pdf') {
            $text = $this->pdfText($media->getPath());
            $analysis->ocr_text = $text;

            if ($text !== null) {
                $provenance['ocr_text'] = 'llm';
            }
        }

        $analysis->provenance = $provenance;
        $analysis->analysis_status = MediaAnalysisStatus::Completed;
        $analysis->analyzed_at = now();
        $analysis->analysis_model_version = $modelVersion;
        $analysis->save();

        $this->fillCoreFields($media, $caption, $analysis->entities ?? []);

        // Chain embeddings after the analysis persists (M11) so the media vector
        // includes the AI text contributed through the search seam.
        dispatch(new GenerateEmbeddingsJob($media));

        $media->reindexOwner();

        event(new ModelPreProcessingCompleted($this->media, 'media_analysis'));
    }

    /**
     * Retries are spent. The analysis row is marked failed, so it is neither reused as a
     * result nor left looking in progress, and the next upload of the same file analyses
     * it again. Completion is still signalled (M12) so the media finalizes on the
     * deterministic layer.
     */
    public function failed(Throwable $exception): void
    {
        $hash = $this->contentHash($this->media->fresh() ?? $this->media);

        if ($hash !== null) {
            MediaAnalysis::query()
                ->where('content_hash', $hash)
                ->where('analysis_status', '!=', MediaAnalysisStatus::Completed->value)
                ->update(['analysis_status' => MediaAnalysisStatus::Failed->value]);
        }

        event(new ModelPreProcessingCompleted($this->media, 'media_analysis'));
    }

    private function fingerprint(Media $media): ?string
    {
        $path = $media->getPath();
        $bytes = is_file($path) ? file_get_contents($path) : false;

        return is_string($bytes) ? ImageFingerprint::of($bytes) : null;
    }

    /**
     * The newest completed analysis, by the active model, whose image is within the configured distance
     * of this one. The comparison is linear: the fingerprints of the newest `near_duplicate_scan_limit`
     * rows are read in pages and the first one in range wins. It runs once per new image that is not an
     * exact duplicate, in a queue job, and rows past the limit are not considered.
     */
    private function nearDuplicate(string $fingerprint, string $hash, string $modelVersion): ?MediaAnalysis
    {
        $distance = max(0, ai_config_int('ai.features.media_analysis.near_duplicate_distance', 0));

        if ($distance === 0) {
            return null;
        }

        $limit = max(1, ai_config_int('ai.features.media_analysis.near_duplicate_scan_limit', 50000));
        $scanned = 0;

        $candidates = MediaAnalysis::query()
            ->select(['id', 'image_fingerprint'])
            ->where('analysis_status', MediaAnalysisStatus::Completed->value)
            ->where('analysis_model_version', $modelVersion)
            ->where('content_hash', '!=', $hash)
            ->whereNotNull('image_fingerprint')
            ->lazyByIdDesc(1000);

        foreach ($candidates as $candidate) {
            if (++$scanned > $limit) {
                break;
            }

            $other = (string) $candidate->image_fingerprint;

            if (ImageFingerprint::isValid($other) && $distance >= ImageFingerprint::distance($fingerprint, $other)) {
                return MediaAnalysis::query()->find($candidate->id);
            }
        }

        return null;
    }

    /**
     * Copies the earlier analysis onto the row of this file without a model call, and records which row
     * it came from in the `analysis` bag. The caption lives on the earlier media, not on the analysis, so
     * it is copied from there when the earlier media still carries the one the model wrote.
     */
    private function reuseNearDuplicate(Media $media, string $hash, string $fingerprint, MediaAnalysis $source): void
    {
        $analysis = MediaAnalysis::query()->firstOrNew(['content_hash' => $hash]);
        $analysis->entities = $source->entities;
        $analysis->idea = $source->idea;
        $analysis->intent = $source->intent;
        $analysis->ocr_text = $source->ocr_text;
        $analysis->provenance = $source->provenance;
        $analysis->analysis = [
            'near_duplicate_of' => $source->id,
            'near_duplicate_distance' => ImageFingerprint::distance($fingerprint, (string) $source->image_fingerprint),
        ];
        $analysis->image_fingerprint = $fingerprint;
        $analysis->analysis_status = MediaAnalysisStatus::Completed;
        $analysis->analyzed_at = now();
        $analysis->analysis_model_version = $source->analysis_model_version;
        $analysis->save();

        $this->fillCoreFields($media, $this->modelCaption($source->content_hash), $analysis->entities ?? []);
        dispatch(new GenerateEmbeddingsJob($media));
        $media->reindexOwner();
        event(new ModelPreProcessingCompleted($this->media, 'media_analysis'));
    }

    /**
     * The description a model wrote for the media of a file, never one a person wrote.
     */
    private function modelCaption(string $hash): ?string
    {
        $earlier = Media::query()
            ->withoutGlobalScopes()
            ->where('custom_properties->content_hash', $hash)
            ->get()
            ->first(static function (Media $candidate): bool {
                $custom = $candidate->custom_properties;
                $description = $custom['description'] ?? null;
                $provenance = is_array($custom['_provenance'] ?? null) ? $custom['_provenance'] : [];

                return is_string($description) && $description !== '' && ($provenance['description'] ?? null) === 'llm';
            });

        $description = $earlier?->custom_properties['description'] ?? null;

        return is_string($description) ? $description : null;
    }

    /**
     * @return array<string, string>
     */
    private function producedFrom(MediaVisionResult $result): array
    {
        return array_filter([
            'entities' => $result->entities !== [] ? 'llm' : null,
            'idea' => $result->idea !== null ? 'llm' : null,
            'intent' => $result->intent !== null ? 'llm' : null,
            'ocr_text' => $result->ocrText !== null ? 'llm' : null,
        ], static fn (?string $v): bool => $v !== null);
    }

    private function contentHash(Media $media): ?string
    {
        $hash = $media->custom_properties['content_hash'] ?? null;

        if (is_string($hash) && $hash !== '') {
            return $hash;
        }

        $path = $media->getPath();

        return is_file($path) ? (hash_file('sha256', $path) ?: null) : null;
    }

    /**
     * Fill empty Core display fields from the analysis (M3c): write only when the
     * field is empty or was previously AI-written, never over a human edit.
     *
     * @param  list<string>  $entities
     */
    private function fillCoreFields(Media $media, ?string $caption, array $entities): void
    {
        $custom = $media->custom_properties;
        $provenance = is_array($custom['_provenance'] ?? null) ? $custom['_provenance'] : [];
        $changed = false;

        if ($caption !== null && $this->mayFill($custom, $provenance, 'description')) {
            $custom['description'] = $caption;
            $provenance['description'] = 'llm';
            $changed = true;
        }

        if ($entities !== [] && $this->mayFill($custom, $provenance, 'keywords')) {
            $custom['keywords'] = $entities;
            $provenance['keywords'] = 'llm';
            $changed = true;
        }

        if ($changed) {
            $custom['_provenance'] = $provenance;
            $media->custom_properties = $custom;
            $media->saveQuietly();
        }
    }

    /**
     * @param  array<string, mixed>  $custom
     * @param  array<string, mixed>  $provenance
     */
    private function mayFill(array $custom, array $provenance, string $field): bool
    {
        $current = $custom[$field] ?? null;

        if ($current === null || $current === '' || $current === []) {
            return true;
        }

        return ($provenance[$field] ?? null) === 'llm';
    }

    private function pdfText(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $text = mb_trim((new PdfParser())->parseFile($path)->getText());

            return $text === '' ? null : $text;
        } catch (Throwable) {
            return null;
        }
    }
}
