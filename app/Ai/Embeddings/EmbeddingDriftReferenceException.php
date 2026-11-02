<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use RuntimeException;

/**
 * An embedding drift reference cannot be saved or compared: it is missing, was made from another
 * sample, or its vectors cannot be set against the ones just measured.
 */
final class EmbeddingDriftReferenceException extends RuntimeException
{
    public static function missing(EmbeddingModelProfile $profile): self
    {
        return new self("No embedding reference saved for profile \"{$profile->key}\". Save one first with --reference=save.");
    }

    public static function unreadable(EmbeddingModelProfile $profile): self
    {
        return new self("The embedding reference saved for profile \"{$profile->key}\" is not readable. Save it again with --reference=save.");
    }

    public static function differentSample(EmbeddingModelProfile $profile): self
    {
        return new self("The embedding reference saved for profile \"{$profile->key}\" was made from a different sample. Save it again with --reference=save.");
    }

    public static function wrongVectorCount(int $expected, int $given): self
    {
        return new self("The drift sample expects {$expected} vectors, {$given} given.");
    }

    public static function lengthDiffers(EmbeddingModelProfile $profile, string $text, int $reference, int $measured): self
    {
        return new self("Profile \"{$profile->key}\": the reference has {$reference} dimensions but the probe returned {$measured} for \"{$text}\"; vectors of different length cannot be compared.");
    }
}
