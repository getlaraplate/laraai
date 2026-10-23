<?php

declare(strict_types=1);

namespace Modules\AI\Ai\SelfHosted;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\AI\Ai\Embeddings\EmbeddingDimensionMismatch;
use Modules\AI\Ai\Embeddings\EmbeddingModelProfile;
use Modules\AI\Ai\Embeddings\EmbeddingModelRegistry;
use Modules\AI\Ai\Embeddings\EmbeddingServerIdentity;
use Modules\AI\Services\CrossEncoderService;
use Throwable;

/**
 * Probes every configured server of `ai.servers` once: each embedding profile through the server it names
 * (answer, model, dimensions, norm, and the batch limit a Text Embeddings Inference server reports in `/info`
 * against the configured batch size), the reranker with two fixed pairs (and its batch limit against 64),
 * while transcription is listed as not probed because a probe would need audio. A server without a URL is
 * not a row: it is not configured.
 */
final readonly class ServerCheck
{
    /**
     * The most pairs a rerank call carries, and so the batch limit the reranker needs.
     */
    private const int RERANK_BATCH = 64;

    public function __construct(
        private AiServers $servers = new AiServers(),
        private ?EmbeddingServerIdentity $identity = null,
        private ?EmbeddingModelRegistry $registry = null,
    ) {}

    /**
     * @return list<array{server: string, capability: string, url: string, ok: bool, detail: string}>
     */
    public function run(): array
    {
        return [...$this->embeddings(), ...$this->rerank(), ...$this->transcription()];
    }

    /**
     * @return list<array{server: string, capability: string, url: string, ok: bool, detail: string}>
     */
    private function embeddings(): array
    {
        $registry = $this->registry ?? resolve(EmbeddingModelRegistry::class);
        $identity = $this->identity ?? new EmbeddingServerIdentity($this->servers);
        $rows = [];

        foreach ($registry->configuredKeys() as $key) {
            if (! str_starts_with($key, 'openai_compatible:')) {
                continue;
            }

            $profile = $registry->get($key);
            $server = $this->servers->forProfile($profile);

            [$ok, $detail] = $this->probeEmbeddings($identity, $profile, $server);
            $rows[] = $this->row($server, 'embeddings', $ok, $key . ': ' . $detail);
        }

        return $rows;
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function probeEmbeddings(EmbeddingServerIdentity $identity, EmbeddingModelProfile $profile, AiServer $server): array
    {
        try {
            $inspection = $identity->inspect($profile);
        } catch (EmbeddingDimensionMismatch $mismatch) {
            return [false, $mismatch->getMessage()];
        } catch (Throwable $exception) {
            return [false, Str::limit($exception->getMessage(), 240)];
        }

        $problem = $inspection->modelConflict($profile) ?? $inspection->normProblem($profile);

        if ($problem !== null) {
            return [false, $problem];
        }

        if ($inspection->maxClientBatchSize !== null && $inspection->maxClientBatchSize < $server->batchSize) {
            return [false, "the server accepts client batches of {$inspection->maxClientBatchSize} but the configured batch size is {$server->batchSize}"];
        }

        $model = $inspection->reportedModel()['model'] ?? 'a model it does not name';

        return [true, sprintf('model %s, %d dimensions, norm %s', $model, $profile->dimensions, number_format($inspection->norm, 4, '.', ''))];
    }

    /**
     * @return list<array{server: string, capability: string, url: string, ok: bool, detail: string}>
     */
    private function rerank(): array
    {
        $server = $this->servers->rerank();

        if (! $server->isConfigured()) {
            return [];
        }

        $pairs = [
            ['query' => 'What does a reranker do?', 'text' => 'A reranker orders passages by their relevance to a query.'],
            ['query' => 'What does a reranker do?', 'text' => 'Bananas are yellow.'],
        ];

        try {
            $result = new CrossEncoderService($server)->scoreWithModel($pairs);
        } catch (Throwable $exception) {
            return [$this->row($server, 'rerank', false, Str::limit($exception->getMessage(), 240))];
        }

        $max = $server->dialect === RerankDialect::Tei->value ? $this->maxClientBatchSize($server) : null;

        if ($max !== null && $max < self::RERANK_BATCH) {
            return [$this->row($server, 'rerank', false, 'the server accepts client batches of ' . $max . ' but the reranker sends up to ' . self::RERANK_BATCH . ' pairs: start it with --max-client-batch-size ' . self::RERANK_BATCH)];
        }

        return [$this->row($server, 'rerank', true, sprintf('dialect %s, model %s, scores %s', $server->dialect, $result->model ?? 'not named', implode(' and ', array_map(static fn (float $score): string => number_format($score, 3, '.', ''), $result->scores))))];
    }

    /**
     * @return list<array{server: string, capability: string, url: string, ok: bool, detail: string}>
     */
    private function transcription(): array
    {
        $server = $this->servers->transcription();

        return $server->isConfigured() ? [$this->row($server, 'transcription', true, 'not probed: it would need audio')] : [];
    }

    private function maxClientBatchSize(AiServer $server): ?int
    {
        $url = $server->url . '/info';
        $request = Http::withHeaders(new ServiceRequestId('rerank', $url)->header())->timeout(5);

        if ($server->apiKey !== null && $server->apiKey !== '') {
            $request = $request->withToken($server->apiKey);
        }

        try {
            $max = $request->get($url)->json('max_client_batch_size');
        } catch (ConnectionException) {
            return null;
        }

        return is_int($max) ? $max : null;
    }

    /**
     * @return array{server: string, capability: string, url: string, ok: bool, detail: string}
     */
    private function row(AiServer $server, string $capability, bool $ok, string $detail): array
    {
        return ['server' => $server->name, 'capability' => $capability, 'url' => ServiceRequestId::endpointOf($server->url), 'ok' => $ok, 'detail' => $detail];
    }
}
