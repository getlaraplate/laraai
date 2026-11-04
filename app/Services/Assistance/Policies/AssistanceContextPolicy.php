<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance\Policies;

use Modules\AI\Enums\ReferenceKind;
use Modules\AI\Exceptions\AssistancePolicyViolationException;
use Modules\AI\Services\Assistance\AssistantPromptContext;
use Modules\AI\Services\Assistance\Contracts\AssistanceSafetyClassifierInterface;
use Modules\AI\Services\Documentation\DocumentationMetadata;
use Throwable;

final readonly class AssistanceContextPolicy
{
    /**
     * @var list<string>
     */
    private const array CITATION_KEYS = ['excerpt', 'kind', 'label', 'reference', 'score', 'section'];

    /**
     * @var list<string>
     */
    private const array RESULT_KEYS = [
        'content',
        'count',
        'edges',
        'entity',
        'excerpt',
        'heading_breadcrumb',
        'id',
        'items',
        'label',
        'locale',
        'module',
        'nodes',
        'reference',
        'relations',
        'safe_source_label',
        'score',
        'title',
        'truncated',
        'type',
        'value',
        'version',
    ];

    public function __construct(
        private AssistanceSafetyClassifierInterface $classifier,
    ) {}

    public function validate(AssistantPromptContext $context): AssistantPromptContext
    {
        if (count($context->safeCitations) > 10 || count($context->authorizedResults) > 50) {
            throw new AssistancePolicyViolationException('context_bounds');
        }

        foreach ($context->safeCitations as $citation) {
            if (! $this->hasOnlyAllowedKeys($citation, self::CITATION_KEYS)) {
                throw new AssistancePolicyViolationException('unsafe_citation_schema');
            }

            $label = $citation['label'] ?? null;
            $reference = $citation['reference'] ?? null;

            if (! is_string($label) || mb_trim($label) === '' || $this->isInternalPath($label)) {
                throw new AssistancePolicyViolationException('unsafe_citation');
            }

            $kind = $citation['kind'] ?? null;

            if ($kind !== null && ! in_array($kind, [ReferenceKind::Documentation->value, ReferenceKind::Content->value], true)) {
                throw new AssistancePolicyViolationException('unsafe_citation_schema');
            }

            if ($reference !== null
                && (! is_string($reference) || ! $this->isSafeReference($reference, $kind))) {
                throw new AssistancePolicyViolationException('unsafe_citation');
            }

            if (isset($citation['section']) && (! is_int($citation['section']) || $citation['section'] < 0)) {
                throw new AssistancePolicyViolationException('unsafe_citation_schema');
            }

            if (isset($citation['excerpt']) && ! is_string($citation['excerpt'])) {
                throw new AssistancePolicyViolationException('unsafe_citation_schema');
            }

            if (isset($citation['score']) && ! is_float($citation['score']) && ! is_int($citation['score'])) {
                throw new AssistancePolicyViolationException('unsafe_citation_schema');
            }
        }

        $this->assertAuthorizedResultSchema($context->authorizedResults);

        foreach ($context->safeCitations as $citation) {
            unset($citation['reference']);
            $this->assertUntrustedDataSafe($citation);
        }
        $this->assertUntrustedDataSafe($context->authorizedResults);

        $serialized = json_encode([
            'policy_version' => $context->policyVersion,
            'presentation_preferences' => $context->presentationPreferences,
            'safe_citations' => $context->safeCitations,
            'authorized_results' => $context->authorizedResults,
        ]);

        if (! is_string($serialized) || mb_strlen($serialized) > 100_000) {
            throw new AssistancePolicyViolationException('context_bounds');
        }

        return $context;
    }

    /**
     * Whether any text inside what a tool returned reads like an instruction. A classifier that cannot
     * answer counts as yes: the result is withheld rather than trusted.
     */
    public function containsInstructions(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsInstructions($item)) {
                    return true;
                }
            }

            return false;
        }

        if (! is_string($value) || mb_trim($value) === '') {
            return false;
        }

        try {
            return $this->classifier->classify($value) !== AssistanceSafetyDecision::Safe;
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function assertAuthorizedResultSchema(array $values): void
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && ! in_array($key, self::RESULT_KEYS, true)) {
                throw new AssistancePolicyViolationException('unsafe_result_schema');
            }

            if (is_object($value) || is_resource($value)) {
                throw new AssistancePolicyViolationException('unsafe_result_schema');
            }

            if (is_array($value)) {
                $this->assertAuthorizedResultSchema($value);
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  list<string>  $allowedKeys
     */
    private function hasOnlyAllowedKeys(array $values, array $allowedKeys): bool
    {
        foreach (array_keys($values) as $key) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $values
     */
    private function assertUntrustedDataSafe(array $values): void
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $this->assertUntrustedDataSafe($value);

                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            if ($key === 'reference') {
                if (! $this->isSafeApplicationReference($value)) {
                    throw new AssistancePolicyViolationException('unsafe_context_value');
                }

                continue;
            }

            if ($this->isInternalPath($value)) {
                throw new AssistancePolicyViolationException('unsafe_context_value');
            }

            try {
                $decision = $this->classifier->classify($value);
            } catch (Throwable) {
                throw new AssistancePolicyViolationException('context_classifier_unavailable');
            }

            if ($decision !== AssistanceSafetyDecision::Safe) {
                throw new AssistancePolicyViolationException('untrusted_context_instruction');
            }
        }
    }

    private function isInternalPath(string $value): bool
    {
        $normalized = str_replace('\\', '/', $value);

        return preg_match('#(?:^|/)(?:srv|home|var|Modules|app)/|\.(?:php|env|sql)$#iu', $normalized) === 1;
    }

    /**
     * A documentation reference is the opaque key of a document, anything else a canonical application path.
     */
    private function isSafeReference(string $reference, mixed $kind): bool
    {
        return $kind === ReferenceKind::Documentation->value
            ? DocumentationMetadata::isDocumentKey($reference)
            : $this->isSafeApplicationReference($reference);
    }

    private function isSafeApplicationReference(string $reference): bool
    {
        return preg_match('#^/app(?:/[A-Za-z0-9][A-Za-z0-9_-]*)+$#', $reference) === 1;
    }
}
