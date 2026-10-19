<?php

declare(strict_types=1);

use Modules\AI\Ai\Rag\RecordingPostProcessor;
use Modules\AI\Ai\Rag\Retrieval\MinimumSimilarity;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;

function scoredDocument(string $source, float $score): Document
{
    return (new Document($source))
        ->setSourceName($source)
        ->setScore($score);
}

it('keeps what scores at least the threshold and drops the rest', function (): void {
    config()->set('ai.features.faq.min_similarity', 0.9);

    $kept = MinimumSimilarity::filter([scoredDocument('a', 0.9), scoredDocument('b', 0.89), scoredDocument('c', 0.95)]);

    expect(array_map(static fn (Document $document): string => $document->getSourceName(), $kept))->toBe(['a', 'c']);
});

it('filters nothing when the threshold is zero, unset or malformed', function (mixed $threshold): void {
    config()->set('ai.features.faq.min_similarity', $threshold);

    expect(MinimumSimilarity::postProcessor())->toBeNull()
        ->and(MinimumSimilarity::filter([scoredDocument('low', -0.2)]))->toHaveCount(1);
})->with([
    'zero' => [0.0],
    'null' => [null],
    'not a number' => ['high'],
]);

it('records the documents of the last question and leaves them untouched', function (): void {
    $recorder = new RecordingPostProcessor;
    $documents = [scoredDocument('a', 0.9)];

    expect($recorder->process(new UserMessage('one'), $documents))->toBe($documents)
        ->and($recorder->documents())->toBe($documents);

    $recorder->process(new UserMessage('two'), []);

    expect($recorder->documents())->toBe([]);
});
