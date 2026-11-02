<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers;

use Modules\AI\Enums\AiModelFeature;

/**
 * The roles a model plays around an answered question, as the query analytics and the data egress
 * indicator name them: the model that writes the answer, the relevance judge, the model that reads
 * the intent of a search, and the embedding profile. Each role but the embedding one is served by
 * the model chosen in Settings for its feature.
 */
final class AiRole
{
    public const string ANSWER = 'answer';

    public const string JUDGE = 'judge';

    public const string INTENT = 'intent';

    public const string EMBEDDING = 'embedding';

    /**
     * @var list<string>
     */
    public const array ALL = [self::ANSWER, self::JUDGE, self::INTENT, self::EMBEDDING];

    /**
     * The feature whose model serves the role; any feature value (`vision`, `translation`...) names
     * its own feature. Null for the embedding role, served by a profile, and for an unknown name.
     */
    public static function feature(string $role): ?AiModelFeature
    {
        return match ($role) {
            self::ANSWER => AiModelFeature::Chat,
            self::JUDGE => AiModelFeature::RelevanceJudge,
            self::INTENT => AiModelFeature::SearchOrchestration,
            self::EMBEDDING => null,
            default => AiModelFeature::tryFrom($role),
        };
    }
}
