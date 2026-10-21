<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Modules\AI\Ai\SelfHosted\SelfHostedServiceException;
use Modules\AI\Ai\SelfHosted\ServiceRequestId;
use Modules\Core\Search\AdaptiveBatchController;
use Modules\Core\Search\Exceptions\EmbeddingsException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\AbstractEmbeddingsProvider;

/**
 * Custom NeuronAI embeddings provider for SentenceTransformers API.
 * Communicates with a self-hosted SentenceTransformers REST endpoint. Every call sends a
 * request id; a call that fails logs it and throws it, so the job log carries it too.
 */
final class SentenceTransformersEmbeddingsProvider extends AbstractEmbeddingsProvider
{
    private readonly Client $client;

    private readonly int $batch_size_limit;

    private readonly string $url;

    public function __construct(
        string $url = 'http://localhost:8000',
        ?string $api_key = null,
        private readonly int $timeout = 10,
        int $batch_size = 128,
        private readonly bool $truncate = true,
        private readonly bool $normalize = true,
        private readonly ?string $model = null,
    ) {
        $this->batch_size_limit = max(1, $batch_size);

        if (preg_match('/^https?:\/\//', $url) === 0) {
            $url = 'http://' . $url;
        }

        $this->url = mb_rtrim($url, '/');

        $options = [
            'base_uri' => $url,
            'timeout' => $timeout,
            'read_timeout' => $timeout,
        ];

        if ($api_key !== null && $api_key !== '') {
            $options['headers'] = ['Authorization' => 'Bearer ' . $api_key];
        }

        $this->client = new Client($options);
    }

    /**
     * @return list<float>
     */
    public function embedText(string $text): array
    {
        $text = str_replace("\n", ' ', mb_convert_encoding($text, 'UTF-8', 'UTF-8'));

        $result = $this->postEmbed([
            'text' => $text,
            'truncation' => $this->truncate,
            'normalize_embeddings' => $this->normalize,
            'max_length' => $this->getEmbeddingLength(),
            ...$this->modelPayload(),
        ]);
        $embeddings = $this->parseEmbeddingBatch($result);

        if ($embeddings === []) {
            throw new EmbeddingsException('SentenceTransformers returned an empty embedding');
        }

        return $embeddings[0];
    }

    /**
     * @param  list<Document>  $documents
     * @return list<Document>
     */
    public function embedDocuments(array $documents): array
    {
        return $this->batchController()->run(
            array_values($documents),
            fn (array $batch): array => $this->embedBatch(array_values($batch)),
        );
    }

    /**
     * Adaptive batcher for the embedding service: start at the configured batch
     * size and, on a timeout / 5xx / empty reply or a batch slower than half the
     * HTTP timeout, halve it and retry fewer texts, then ramp back up. This keeps
     * a weak or temporary service from saturating without per-server tuning.
     */
    private function batchController(): AdaptiveBatchController
    {
        return new AdaptiveBatchController(
            minBatch: 1,
            maxBatch: $this->batch_size_limit,
            targetLatencySeconds: max(1.0, $this->timeout / 2),
            startBatch: $this->batch_size_limit,
        );
    }

    /**
     * @param  list<Document>  $batch
     * @return list<Document>
     */
    private function embedBatch(array $batch): array
    {
        $texts = array_map(
            fn (Document $doc): string => $this->documentText($doc),
            $batch,
        );

        $result = $this->postEmbed([
            'texts' => $texts,
            'truncation' => $this->truncate,
            'normalize_embeddings' => $this->normalize,
            'max_length' => $this->getEmbeddingLength(),
            ...$this->modelPayload(),
        ]);
        $embeddings = $this->parseEmbeddingBatch($result);
        $batch_count = count($batch);
        $embedding_count = count($embeddings);

        throw_if($embedding_count !== $batch_count, EmbeddingsException::class, "Embeddings count mismatch: expected {$batch_count}, got {$embedding_count}");

        $processed = [];

        for ($i = 0; $i < $batch_count; $i++) {
            $batch[$i]->setEmbedding($embeddings[$i]);
            $processed[] = $batch[$i];
        }

        return $processed;
    }

    /**
     * Posts one `/embed` call with its request id and decodes the answer.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws SelfHostedServiceException when the service cannot be reached or answers an error status
     */
    private function postEmbed(array $payload): mixed
    {
        $request_id = new ServiceRequestId('sentence_transformers', $this->url . '/embed');

        try {
            $response = $this->client->post('embed', [
                'json' => $payload,
                'headers' => $request_id->header(),
            ]);
        } catch (GuzzleException $exception) {
            $request_id->logFailure(ServiceRequestId::statusOf($exception));

            throw $request_id->exception($exception);
        }

        return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function documentText(Document $document): string
    {
        return mb_convert_encoding($document->getContent(), 'UTF-8', 'UTF-8');
    }

    /**
     * @return list<list<float>>
     */
    private function parseEmbeddingBatch(mixed $payload): array
    {
        if (! is_array($payload) || ! array_key_exists('embeddings', $payload)) {
            throw new EmbeddingsException('SentenceTransformers returned unexpected format');
        }

        if (! is_array($payload['embeddings'])) {
            throw new EmbeddingsException('SentenceTransformers returned unexpected format');
        }

        // The embeddings are stored under the active profile, whatever model computed them. A service
        // that names the model it ran lets a wrong one be refused here, instead of poisoning the index.
        $answered = $payload['model'] ?? null;

        if (is_string($answered) && $answered !== '' && is_string($this->model) && $this->model !== ''
            && ! EmbeddingModelProfile::sameServiceModel($answered, $this->model)) {
            throw new EmbeddingsException("SentenceTransformers served model \"{$answered}\" but \"{$this->model}\" was requested");
        }

        $batch = [];

        foreach ($payload['embeddings'] as $embedding) {
            $batch[] = $this->parseEmbeddingVector($embedding);
        }

        return $batch;
    }

    /**
     * @return list<float>
     */
    private function parseEmbeddingVector(mixed $embedding): array
    {
        if (! is_array($embedding)) {
            throw new EmbeddingsException('SentenceTransformers returned invalid embedding vector');
        }

        $vector = [];

        foreach ($embedding as $component) {
            if (! is_int($component) && ! is_float($component)) {
                throw new EmbeddingsException('SentenceTransformers returned invalid embedding component');
            }

            $vector[] = (float) $component;
        }

        return $vector;
    }

    private function getEmbeddingLength(): int
    {
        return 512;
    }

    /**
     * @return array<string, string>
     */
    private function modelPayload(): array
    {
        if ($this->model === null || $this->model === '') {
            return [];
        }

        return ['model' => $this->model];
    }
}
