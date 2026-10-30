<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use Closure;
use InvalidArgumentException;
use Modules\AI\Ai\Providers\Models\ProviderConfiguration;
use Modules\AI\Ai\SelfHosted\AiServers;

/**
 * Resolves embedding-model profiles from `ai.features.embeddings.models`.
 * A profile key is `provider:service_model`, split at the first colon, and the
 * profile declares its own `dimensions` and `similarity`. The active profile can
 * be overridden for the duration of a callback with `withActive()`.
 */
final class EmbeddingModelRegistry
{
    private ?string $override = null;

    public function __construct(
        private readonly ProviderConfiguration $providers = new ProviderConfiguration,
        private readonly AiServers $servers = new AiServers,
    ) {}

    public function active(): EmbeddingModelProfile
    {
        return $this->get($this->override ?? $this->activeKey());
    }

    /**
     * The profile keys whose provider is configured, in declaration order. A profile of the
     * `openai_compatible` provider is configured when the server it names, or else the default
     * embeddings server, has a URL.
     *
     * @return list<string>
     */
    public function configuredKeys(): array
    {
        return array_values(array_filter(
            $this->keys(),
            fn (string $key): bool => $this->isConfigured($key),
        ));
    }

    /**
     * The active key: the model that serves search, the managed setting
     * `features.embeddings.serving_model` (read as `ai.features.embeddings.serving_model`), else the
     * first configured profile, else the first declared one.
     */
    public function activeKey(): string
    {
        $configured = config('ai.features.embeddings.serving_model');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->configuredKeys()[0] ?? $this->keys()[0] ?? '';
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys((array) config('ai.features.embeddings.models', [])));
    }

    /**
     * Runs the callback with `$key` as the active profile; the previous override is restored afterwards, also on failure.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function withActive(string $key, Closure $callback): mixed
    {
        $previous = $this->override;
        $this->override = $key;

        try {
            return $callback();
        } finally {
            $this->override = $previous;
        }
    }

    public function get(string $key): EmbeddingModelProfile
    {
        /** @var array<string, array<string, mixed>> $models */
        $models = (array) config('ai.features.embeddings.models', []);

        if (! array_key_exists($key, $models)) {
            throw new InvalidArgumentException("Unknown embedding model profile: {$key}");
        }

        $model = $models[$key];
        $dimensions = (int) ($model['dimensions'] ?? 0);

        if ($dimensions < 1) {
            throw new InvalidArgumentException("Embedding model profile {$key} declares no valid dimensions");
        }

        [$provider, $serviceModel] = array_pad(explode(':', $key, 2), 2, '');

        if ($provider === '' || $serviceModel === '') {
            throw new InvalidArgumentException("Malformed embedding model profile key: {$key}");
        }

        return new EmbeddingModelProfile(
            key: $key,
            provider: $provider,
            serviceModel: $serviceModel,
            dimensions: $dimensions,
            queryPrefix: (string) ($model['query_prefix'] ?? ''),
            passagePrefix: (string) ($model['passage_prefix'] ?? ''),
            similarity: (string) ($model['similarity'] ?? 'cosine'),
            normalize: (bool) ($model['normalize'] ?? false),
            server: is_string($model['server'] ?? null) && $model['server'] !== '' ? $model['server'] : null,
        );
    }

    private function isConfigured(string $key): bool
    {
        $provider = explode(':', $key, 2)[0];

        if ($provider !== 'openai_compatible') {
            return $this->providers->isConfigured($provider);
        }

        /** @var array<string, array<string, mixed>> $models */
        $models = (array) config('ai.features.embeddings.models', []);
        $server = $models[$key]['server'] ?? null;

        return $this->servers->named(is_string($server) && $server !== '' ? $server : AiServers::DEFAULT_EMBEDDINGS)->isConfigured();
    }
}
