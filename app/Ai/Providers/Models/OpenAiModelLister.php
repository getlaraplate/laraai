<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers\Models;

use Illuminate\Support\Facades\Http;
use Override;
use UnexpectedValueException;

/**
 * OpenAI lists every model family without capabilities: the families no AI feature can use
 * are dropped by id, everything else is kept with unknown capabilities. A server behind a base URL
 * is not OpenAI: its model ids are its own, so none is dropped.
 */
final readonly class OpenAiModelLister implements ModelLister
{
    private const array INCOMPATIBLE = [
        'embedding', 'whisper', 'tts', 'audio', 'realtime', 'transcribe',
        'dall-e', 'image', 'moderation', 'babbage', 'davinci', 'instruct',
    ];

    public function __construct(private string $apiKey, private string $baseUrl = '') {}

    #[Override]
    public function list(): array
    {
        $base = $this->baseUrl === '' ? 'https://api.openai.com/v1' : mb_rtrim($this->baseUrl, '/');

        $data = Http::connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::REQUEST_TIMEOUT)
            ->withToken($this->apiKey === '' ? 'none' : $this->apiKey)
            ->get($base . '/models')
            ->throw()
            ->json('data');

        if (! is_array($data)) {
            throw new UnexpectedValueException('OpenAI answered without a model list.');
        }

        $models = [];

        foreach ($data as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;

            if (is_string($id) && $id !== '' && ($this->baseUrl !== '' || ! self::isIncompatible($id))) {
                $models[] = new ListedModel($id, null);
            }
        }

        return $models;
    }

    private static function isIncompatible(string $id): bool
    {
        return array_any(self::INCOMPATIBLE, static fn (string $marker): bool => str_contains($id, $marker));
    }
}
