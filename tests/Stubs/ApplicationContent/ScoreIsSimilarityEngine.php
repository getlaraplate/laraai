<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\ApplicationContent;

use Modules\Core\Search\Contracts\IReportsVectorSimilarity;
use Modules\Core\Search\DTOs\VectorProfile;

/**
 * An engine whose vector score is the similarity itself, for a `cosine` profile only, so a test reads
 * the floor recommendation straight from the scores it hands in.
 */
final class ScoreIsSimilarityEngine implements IReportsVectorSimilarity
{
    public function vectorSimilarity(float $score, VectorProfile $profile): ?float
    {
        return $profile->similarity === 'cosine' ? $score : null;
    }
}
