<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance;

use InvalidArgumentException;

final readonly class AssistantPromptContext
{
    private const array ALLOWED_PRESENTATION_PREFERENCES = [
        'accessibility',
        'locale',
        'response_format',
        'verbosity',
    ];

    private const array ALLOWED_ACCESSIBILITY_PROFILES = [
        'dyslexia_friendly',
        'reduced_cognitive_load',
        'screen_reader',
    ];

    private const array ALLOWED_RESPONSE_FORMATS = ['markdown', 'plain_text'];

    private const array ALLOWED_VERBOSITY = ['concise', 'detailed', 'short', 'standard'];

    /**
     * @param  array<string, mixed>  $presentationPreferences
     * @param  list<array<string, mixed>>  $safeCitations
     * @param  list<array<string, mixed>>  $authorizedResults
     * @param  list<array{speaker: string, content: string}>  $recentTurns  the earlier turns of the conversation the
     *                                                                      person saw, oldest first, when conversation
     *                                                                      memory is on ({@see History\ConversationHistory})
     */
    public function __construct(
        public string $policyVersion,
        public array $presentationPreferences,
        public array $safeCitations,
        public array $authorizedResults,
        public array $recentTurns = [],
    ) {
        if (mb_trim($policyVersion) === '') {
            throw new InvalidArgumentException('Assistant policy version cannot be blank.');
        }

        foreach (array_keys($presentationPreferences) as $key) {
            if (! is_string($key) || ! in_array($key, self::ALLOWED_PRESENTATION_PREFERENCES, true)) {
                throw new InvalidArgumentException('Unsupported assistant presentation preference.');
            }
        }

        $this->assertPresentationPreferences($presentationPreferences);

        AssistantControlPlaneData::assertPromptSafe($presentationPreferences);
        AssistantControlPlaneData::assertPromptSafe($safeCitations);
        AssistantControlPlaneData::assertPromptSafe($authorizedResults);
        AssistantControlPlaneData::assertPromptSafe($recentTurns);
    }

    /**
     * @param  array<string, mixed>  $preferences
     */
    private function assertPresentationPreferences(array $preferences): void
    {
        if (isset($preferences['locale'])
            && (! is_string($preferences['locale'])
                || preg_match('/^[a-z]{2,3}(?:[-_][A-Z]{2})?$/', $preferences['locale']) !== 1)) {
            throw new InvalidArgumentException('Unsupported assistant locale preference.');
        }

        if (isset($preferences['verbosity'])
            && (! is_string($preferences['verbosity'])
                || ! in_array($preferences['verbosity'], self::ALLOWED_VERBOSITY, true))) {
            throw new InvalidArgumentException('Unsupported assistant verbosity preference.');
        }

        if (isset($preferences['response_format'])
            && (! is_string($preferences['response_format'])
                || ! in_array($preferences['response_format'], self::ALLOWED_RESPONSE_FORMATS, true))) {
            throw new InvalidArgumentException('Unsupported assistant response format preference.');
        }

        if (isset($preferences['accessibility'])) {
            $accessibility = $preferences['accessibility'];

            if (! is_array($accessibility) || ! array_is_list($accessibility) || count($accessibility) > 3) {
                throw new InvalidArgumentException('Unsupported assistant accessibility preference.');
            }

            foreach ($accessibility as $profile) {
                if (! is_string($profile) || ! in_array($profile, self::ALLOWED_ACCESSIBILITY_PROFILES, true)) {
                    throw new InvalidArgumentException('Unsupported assistant accessibility preference.');
                }
            }
        }
    }
}
