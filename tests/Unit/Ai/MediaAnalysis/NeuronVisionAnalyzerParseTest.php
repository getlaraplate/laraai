<?php

declare(strict_types=1);

use Modules\AI\Ai\Agents\ChatAgent;
use Modules\AI\Ai\MediaAnalysis\MediaAnalysisModelProfile;
use Modules\AI\Ai\MediaAnalysis\Vision\NeuronVisionAnalyzer;
use Modules\AI\Data\ImageAnalysisData;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Testing\FakeAIProvider;

/**
 * An analyzer whose model is Neuron's fake provider, answering with the given replies in order; with
 * none, it fails like a provider that is down. What it was asked is read from $provider.
 *
 * @param  list<string>  $replies
 */
function visionAnalyzerAnswering(array $replies, ?FakeAIProvider &$provider = null): NeuronVisionAnalyzer
{
    $provider = new FakeAIProvider(...array_map(static fn (string $reply): AssistantMessage => new AssistantMessage($reply), $replies));

    return new NeuronVisionAnalyzer(static fn (): ChatAgent => ChatAgent::make(systemPrompt: 'Analyze.')->setAiProvider($provider));
}

function visionProfile(): MediaAnalysisModelProfile
{
    return Mockery::mock(MediaAnalysisModelProfile::class);
}

/**
 * @template T
 *
 * @param  Closure(string): T  $callback
 * @return T
 */
function withVisionImage(Closure $callback): mixed
{
    $path = tempnam(sys_get_temp_dir(), 'vision-');
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true));

    try {
        return $callback($path);
    } finally {
        unlink($path);
    }
}

it('reads the analysis from what the model answers, in a fence or not', function (string $reply): void {
    $result = withVisionImage(fn (string $path) => visionAnalyzerAnswering([$reply])->analyze($path, 'image/png', visionProfile()));

    expect($result->caption)->toBe('A cat')
        ->and($result->entities)->toBe(['cat'])
        ->and($result->idea)->toBe('rest')
        ->and($result->intent)->toBe('inform')
        ->and($result->ocrText)->toBeNull();
})->with([
    'plain' => ['{"caption": "A cat", "entities": ["cat", ""], "idea": "rest", "intent": "inform", "ocr_text": ""}'],
    'in a Markdown fence' => ["```json\n{\"caption\": \"A cat\", \"entities\": [\"cat\"], \"idea\": \" rest \", \"intent\": \"inform\", \"ocr_text\": \"  \"}\n```"],
]);

it('sends the image and the schema of the analysis', function (): void {
    $analyzer = visionAnalyzerAnswering(['{"caption":"A cat","entities":["cat"],"idea":"rest","intent":"inform","ocr_text":""}'], $provider);
    withVisionImage(fn (string $path) => $analyzer->analyze($path, 'image/png', visionProfile()));

    $record = $provider->getRecorded()[0];
    $blocks = $record->messages[0]->getContentBlocks();

    expect($record->structuredClass)->toBe(ImageAnalysisData::class)
        ->and(json_encode($record->structuredSchema))->toContain('ocr_text')->toContain('entities')
        ->and(array_filter($blocks, static fn (mixed $block): bool => $block instanceof ImageContent))->toHaveCount(1);
});

it('throws when the answer never fits, so the job retries instead of storing an empty result', function (array $replies): void {
    $analyzer = visionAnalyzerAnswering($replies);
    withVisionImage(fn (string $path) => $analyzer->analyze($path, 'image/png', visionProfile()));
})->with([
    'prose twice' => [['Sorry, I cannot help with that image.', 'Still no.']],
    'the provider is down' => [[]],
])->throws(Exception::class);

it('asks again once when the first answer is not an analysis', function (): void {
    $analyzer = visionAnalyzerAnswering(['Sorry, no.', '{"caption":"A dog","entities":["dog"],"idea":"rest","intent":"inform","ocr_text":""}'], $provider);
    $result = withVisionImage(fn (string $path) => $analyzer->analyze($path, 'image/png', visionProfile()));

    $provider->assertCallCount(2);

    expect($result->caption)->toBe('A dog');
});

it('gives an empty result for a file that cannot be read, without calling the model', function (): void {
    $result = visionAnalyzerAnswering(['{"caption":"never"}'], $provider)->analyze('/no/such/image.png', 'image/png', visionProfile());

    expect($result->caption)->toBeNull()
        ->and($result->entities)->toBe([])
        ->and($provider->getRecorded())->toBe([]);
});
