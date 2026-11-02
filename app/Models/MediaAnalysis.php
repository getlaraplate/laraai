<?php

declare(strict_types=1);

namespace Modules\AI\Models;

use Modules\AI\Database\Factories\MediaAnalysisFactory;
use Modules\AI\Enums\AITables;
use Modules\AI\Enums\MediaAnalysisStatus;
use Modules\Core\Overrides\Model;
use Override;

/**
 * AI-owned analysis of a media file, keyed by the file's `content_hash` (M3b,
 * M15): one row per distinct file, shared by every duplicated media row that
 * hashes to it. Core never references this model — it is the AI module's private
 * extension of the shared media, holding the AI-derived data (entities, idea,
 * intent, OCR, transcript), a provenance ledger, and the analysis lifecycle.
 *
 * @property int $id
 * @property string $content_hash
 * @property string|null $image_fingerprint
 * @property list<string>|null $entities
 * @property string|null $idea
 * @property string|null $intent
 * @property string|null $ocr_text
 * @property string|null $transcript
 * @property array<string, mixed>|null $analysis
 * @property array<string, string>|null $provenance
 * @property MediaAnalysisStatus $analysis_status
 * @property string|null $analysis_model_version
 * @property \Carbon\CarbonImmutable|null $analyzed_at
 */
final class MediaAnalysis extends Model
{
    /**
     * Force hard deletes (not the dynamic per-model soft-delete setting). This
     * table is a dedup cache keyed uniquely by `content_hash`; a soft-deleted row
     * would keep occupying the unique index and make the next `firstOrCreate` for
     * the same hash collide. Refcount cleanup (M19) purges outright.
     */
    protected bool $softDeletesEnabled = false;

    /**
     * @var string
     */
    #[Override]
    protected $table = AITables::MediaAnalyses->value;

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'content_hash',
        'image_fingerprint',
        'entities',
        'idea',
        'intent',
        'ocr_text',
        'transcript',
        'analysis',
        'provenance',
        'analysis_status',
        'analysis_model_version',
        'analyzed_at',
    ];

    protected static function newFactory(): MediaAnalysisFactory
    {
        return MediaAnalysisFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'entities' => 'array',
            'analysis' => 'array',
            'provenance' => 'array',
            'analysis_status' => MediaAnalysisStatus::class,
            'analyzed_at' => 'datetime',
        ];
    }
}
