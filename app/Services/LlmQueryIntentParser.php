<?php

declare(strict_types=1);

namespace Modules\AI\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Helpers\LocaleContext;
use Modules\Core\Search\Contracts\IQueryIntentParser;
use Modules\Core\Search\Services\DateExpressionResolver;

/**
 * LLM-powered query intent parser.
 *
 * Delegates to LlmSearchService for structured extraction of keywords,
 * date filters, and expanded query from raw user input.
 *
 * The dates are read by the rules of {@see DateExpressionResolver} first;
 * the interval the model gives is used only when the rules read none.
 */
final readonly class LlmQueryIntentParser implements IQueryIntentParser
{
    public function __construct(
        private LlmSearchService $llm,
        private DateExpressionResolver $dates = new DateExpressionResolver,
    ) {}

    public function parse(string $query): array
    {
        $intent = $this->llm->extractSearchIntent($query);

        $expanded = $intent['query_expansion']['must'] ?? null;
        $expanded = is_string($expanded) && $expanded !== '' ? $expanded : $query;
        $locale = LocaleContext::get();

        return [
            'keywords' => $intent['keywords'],
            'date_range' => $this->dates->resolve($query, CarbonImmutable::now(), $locale)
                ?? $this->parseDateRange($intent['filters']['date_range'] ?? null),
            'recent' => $this->dates->asksForRecent($query, $locale),
            'query' => [
                'expanded' => $expanded,
            ],
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function parseDateRange(mixed $date_range): ?array
    {
        if (! is_array($date_range)) {
            return null;
        }

        $parsed = [];

        foreach ($date_range as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            $parsed[$key] = (string) $value;
        }

        return $parsed === [] ? null : $parsed;
    }
}
