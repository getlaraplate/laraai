<?php

declare(strict_types=1);

namespace Modules\AI\Ai\MediaAnalysis\Contracts;

use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;

/**
 * Speech-to-text transcription of an audio/video file (M17, M20). Returns the
 * transcript in the source (spoken) language, or null when no backend is
 * configured or nothing could be transcribed. Injected as a contract so the
 * analysis job is testable with a fake; the concrete OpenAI-style transcription backend
 * is wired in Task 8.
 */
interface MediaTranscriber
{
    public function transcribe(string $path, string $mimeType, MediaAnalysisModelProfile $profile): ?string;
}
