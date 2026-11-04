<?php

declare(strict_types=1);

namespace Modules\AI\Services\ApplicationContent;

use Modules\AI\Enums\ReferenceKind;
use Modules\AI\Services\ApplicationContent\Data\ApplicationContentRoutingDecision;
use Modules\AI\Services\ApplicationContent\Enums\ApplicationContentRoutingStatus;
use Modules\Core\ApplicationContent\Data\ApplicationContentResult;

final class ApplicationContentCitationMapper
{
    private bool $attempted = false;

    private bool $clarificationRequired = false;

    /**
     * @var list<array<string, mixed>>
     */
    private array $citations = [];

    /**
     * @var list<array<string, mixed>>
     */
    private array $results = [];

    public function reset(): void
    {
        $this->attempted = false;
        $this->clarificationRequired = false;
        $this->citations = [];
        $this->results = [];
    }

    public function markAttempted(): void
    {
        $this->attempted = true;
    }

    public function recordRoutingDecision(ApplicationContentRoutingDecision $decision): void
    {
        $this->clarificationRequired = $decision->status === ApplicationContentRoutingStatus::ClarificationRequired;
    }

    public function record(ApplicationContentResult $result): void
    {
        $this->attempted = true;

        foreach ($this->citationsFor($result) as $index => $citation) {
            if (count($this->citations) >= 10 || $this->hasReference($citation['reference'])) {
                continue;
            }

            $this->citations[] = $citation;
            $this->results[] = $this->resultsFor($result)[$index];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function citationsFor(ApplicationContentResult $result): array
    {
        return array_map(static fn ($hit): array => [
            'kind' => ReferenceKind::Content->value,
            'label' => $hit->label,
            'reference' => $hit->canonicalReference,
            'excerpt' => mb_substr($hit->excerpt, 0, 300),
        ], $result->hits);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function resultsFor(ApplicationContentResult $result): array
    {
        return array_map(static fn ($hit): array => array_filter([
            'content' => $hit->excerpt,
            'title' => $hit->label,
            'reference' => $hit->canonicalReference,
            'locale' => $hit->locale,
            'version' => $hit->revision,
            'truncated' => $hit->truncated,
        ], static fn (mixed $value): bool => $value !== null), $result->hits);
    }

    public function attempted(): bool
    {
        return $this->attempted;
    }

    public function hasEvidence(): bool
    {
        return $this->results !== [];
    }

    public function clarificationRequired(): bool
    {
        return $this->clarificationRequired;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function citations(): array
    {
        return $this->citations;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function results(): array
    {
        return $this->results;
    }

    private function hasReference(string $reference): bool
    {
        return collect($this->citations)->contains(
            static fn (array $citation): bool => $citation['reference'] === $reference,
        );
    }
}
