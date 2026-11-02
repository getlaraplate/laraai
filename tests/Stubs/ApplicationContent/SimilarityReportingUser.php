<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\ApplicationContent;

use Modules\Core\Models\User;

/**
 * A user model whose search engine tells the similarity behind a vector score, so the tuning command
 * can recommend a similarity floor in a feature test.
 */
final class SimilarityReportingUser extends User
{
    public function searchableUsing(): ScoreIsSimilarityEngine
    {
        return new ScoreIsSimilarityEngine;
    }
}
