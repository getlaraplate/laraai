<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Modules\AI\Enums\AITables;
use Modules\Core\Helpers\MigrateUtils;

return new class extends Migration
{
    public function up(): void
    {
        $table_name = AITables::MediaAnalyses->value;

        Schema::create($table_name, function (Blueprint $table) use ($table_name): void {
            $table->id();

            // Keyed by the media file's content hash (M15): one analysis row per
            // distinct file, shared by every duplicated media row that hashes to it.
            $table->string('content_hash', 64)->unique("{$table_name}_content_hash_UN")
                ->comment('sha256 of the media file bytes; the dedup key');

            // A 64-bit difference hash, written for every analysed image so near-duplicate reuse can be
            // switched on later (`features.media_analysis.near_duplicate_distance`).
            $table->char('image_fingerprint', 16)->nullable()->index("{$table_name}_image_fingerprint_IDX")
                ->comment('Perceptual hash of an image (16 hex characters); null for other media or when it could not be computed');

            $table->json('entities')->nullable()->comment('Recognized subjects/objects');
            $table->text('idea')->nullable()->comment('Interpretive: the central concept');
            $table->text('intent')->nullable()->comment('Interpretive: the communicative purpose');
            $table->longText('ocr_text')->nullable()->comment('Text read from the media (OCR)');
            $table->longText('transcript')->nullable()->comment('Speech transcription, source language');
            $table->json('analysis')->nullable()->comment('Extension bag for future type-dependent attributes');
            $table->json('provenance')->nullable()->comment('Which fields AI generated (incl. filled Core fields)');
            $table->string('analysis_status', 20)->default('pending')->comment('pending|processing|completed|failed');
            $table->string('analysis_model_version')->nullable()->comment('Active analysis model at write time');
            $table->timestamp('analyzed_at')->nullable()->comment('When analysis completed');

            MigrateUtils::timestamps(
                $table,
                hasCreateUpdate: true,
                hasSoftDelete: true,
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(AITables::MediaAnalyses->value);
    }
};
