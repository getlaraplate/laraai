<?php

declare(strict_types=1);

namespace Modules\AI\Services\Assistance;

use Modules\AI\Exceptions\AssistancePolicyViolationException;

/**
 * The check of a text that a tool argument carries for the user to read, such as the reason of a proposal or the
 * title of a widget: the model writes it, steered by text that is not fully ours, and the application shows it.
 *
 * The text must be plain, trimmed and bounded: no control character, no markup bracket or backtick, no link, and it
 * must pass the output guardrails. A text the pattern cannot read (one that is not UTF-8) is refused: the check
 * fails closed.
 */
final class PlainText
{
    /**
     * The text trimmed and checked, or null when it is not plain text of 1 to `$maxLength` characters.
     */
    public static function validated(mixed $text, int $maxLength, AssistanceGuardrailPipeline $guardrails): ?string
    {
        if (! is_string($text)) {
            return null;
        }

        $text = mb_trim($text);

        if ($text === '' || $maxLength < mb_strlen($text) || preg_match('/[\p{C}]|[<>`]|https?:\/\//u', $text) !== 0) {
            return null;
        }

        try {
            return $guardrails->validateOutput($text);
        } catch (AssistancePolicyViolationException) {
            return null;
        }
    }
}
