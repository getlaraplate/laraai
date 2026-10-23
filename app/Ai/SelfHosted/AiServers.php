<?php

declare(strict_types=1);

namespace Modules\AI\Ai\SelfHosted;

use function ai_config_int;
use function ai_config_nullable_string;
use function ai_config_string;

use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;

/**
 * Resolves the servers declared in `ai.servers`. An embeddings server that has no URL of its own falls back to
 * the default `embeddings` one for URL, key, timeout and batch size; `rerank` and `transcription` never fall back.
 */
final readonly class AiServers
{
    public const string DEFAULT_EMBEDDINGS = 'embeddings';

    private const array STANDALONE = ['rerank', 'transcription'];

    public function named(string $name): AiServer
    {
        if (in_array($name, self::STANDALONE, true) || $name === self::DEFAULT_EMBEDDINGS) {
            return $this->read($name);
        }

        $default = $this->read(self::DEFAULT_EMBEDDINGS);
        $server = $this->read($name, $default);

        if ($server->isConfigured()) {
            return $server;
        }

        return new AiServer($name, $default->url, $default->apiKey, $default->timeout, $default->batchSize, $server->dialect, $server->model);
    }

    public function forProfile(EmbeddingModelProfile $profile): AiServer
    {
        return $this->named($profile->server ?? self::DEFAULT_EMBEDDINGS);
    }

    public function rerank(): AiServer
    {
        return $this->named('rerank');
    }

    public function transcription(): AiServer
    {
        return $this->named('transcription');
    }

    /**
     * @param  AiServer|null  $inherit  the server whose timeout and batch size stand in for the ones this entry does not set
     */
    private function read(string $name, ?AiServer $inherit = null): AiServer
    {
        $key = 'ai.servers.' . $name . '.';

        return new AiServer(
            name: $name,
            url: mb_rtrim(ai_config_string($key . 'url', ''), '/'),
            apiKey: ai_config_nullable_string($key . 'api_key'),
            timeout: ai_config_int($key . 'timeout', $inherit->timeout ?? 30),
            batchSize: max(1, ai_config_int($key . 'batch_size', $inherit->batchSize ?? 32)),
            dialect: ai_config_nullable_string($key . 'dialect'),
            model: ai_config_nullable_string($key . 'model'),
        );
    }
}
