<?php

declare(strict_types=1);

namespace Modules\AI\Data;

use Modules\AI\Ai\MediaAnalysis\MediaVisionResult;
use NeuronAI\StructuredOutput\SchemaProperty;

/**
 * The structured output the vision model is asked for when it analyzes an image. A text the model
 * leaves empty is no text: {@see self::toResult()} turns it into null. A field it leaves out is the same:
 * every field has a default and is declared not required, so a partial answer is stored, not retried.
 */
final class ImageAnalysisData
{
    #[SchemaProperty(description: 'One sentence describing the subjects and what they do, for example "a man smiling outdoors".', required: false)]
    public string $caption = '';

    /**
     * @var list<string>
     */
    #[SchemaProperty(description: 'The concrete subjects and objects visible, for example ["man", "tree"].', required: false)]
    public array $entities = [];

    #[SchemaProperty(description: 'The central concept the image conveys.', required: false)]
    public string $idea = '';

    #[SchemaProperty(description: 'The communicative purpose of the image.', required: false)]
    public string $intent = '';

    #[SchemaProperty(description: 'The text visible in the image, or an empty string when there is none.', required: false)]
    public string $ocr_text = '';

    public function toResult(): MediaVisionResult
    {
        return new MediaVisionResult(
            caption: self::nullable($this->caption),
            entities: array_values(array_filter($this->entities, static fn (mixed $entity): bool => is_string($entity) && $entity !== '')),
            idea: self::nullable($this->idea),
            intent: self::nullable($this->intent),
            ocrText: self::nullable($this->ocr_text),
        );
    }

    private static function nullable(string $text): ?string
    {
        $trimmed = mb_trim($text);

        return $trimmed === '' ? null : $trimmed;
    }
}
