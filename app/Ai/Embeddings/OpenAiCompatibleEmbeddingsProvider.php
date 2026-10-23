<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

use JsonException;
use Modules\AI\Ai\SelfHosted\AiServer;
use Modules\AI\Ai\SelfHosted\SelfHostedServiceException;
use Modules\AI\Ai\SelfHosted\ServiceRequestId;
use Modules\Core\Search\AdaptiveBatchController;
use Modules\Core\Search\Exceptions\EmbeddingsException;
use NeuronAI\Exceptions\HttpException;
use NeuronAI\HttpClient\Curl\CurlHttpClient;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\HttpClient\HttpRequest;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings;
use Override;

/**
 * Embeddings from any server that speaks `POST /v1/embeddings` (Text Embeddings Inference, Infinity, Ollama,
 * LocalAI, a cloud API): Neuron's OpenAI-like provider, which keeps the request body and the credential-free
 * error messages, with what Neuron does not do. It splits documents by the server's batch size, halving it when
 * a request fails or is slow (Neuron's fixed chunks of 100 are refused by a TEI at its default limit of 32), sends
 * one request id per request, and fails with a {@see SelfHostedServiceException} that carries it.
 */
final class OpenAiCompatibleEmbeddingsProvider extends OpenAILikeEmbeddings
{
    private readonly int $batchSize;

    private readonly int $timeout;

    public function __construct(
        AiServer $server,
        string $model,
        ?int $dimensions = null,
        ?HttpClientInterface $httpClient = null,
    ) {
        parent::__construct(
            baseUri: $server->url . '/v1',
            key: $server->apiKey ?? '',
            model: $model,
            dimensions: $dimensions,
            httpClient: $httpClient ?? new CurlHttpClient(timeout: $server->timeout),
        );

        if ($server->apiKey === null || $server->apiKey === '') {
            unset($this->httpHeaders['Authorization']);
        }

        $this->batchSize = $server->batchSize;
        $this->timeout = $server->timeout;
    }

    /**
     * @return list<float>
     */
    #[Override]
    public function embedText(string $text): array
    {
        return $this->request([$text])[0];
    }

    /**
     * Neuron's contract allows any keys (`Document[]`); the batches are numbered from zero.
     *
     * @param  array<array-key, Document>  $documents
     * @return list<Document>
     */
    #[Override]
    public function embedDocuments(array $documents): array
    {
        $controller = new AdaptiveBatchController(
            minBatch: 1,
            maxBatch: $this->batchSize,
            targetLatencySeconds: max(1.0, $this->timeout / 2),
            startBatch: $this->batchSize,
        );

        return $controller->run(array_values($documents), $this->embedBatch(...));
    }

    /**
     * @param  list<Document>  $batch
     * @return list<Document>
     */
    private function embedBatch(array $batch): array
    {
        $vectors = $this->request(array_map(static fn (Document $document): string => $document->getContent(), $batch));

        foreach ($batch as $position => $document) {
            $document->setEmbedding($vectors[$position]);
        }

        return $batch;
    }

    /**
     * One `/embeddings` request for `$inputs`, answered with one vector per input in the order of the inputs.
     *
     * @param  list<string>  $inputs
     *
     * @throws SelfHostedServiceException when the server cannot be reached or answers an error status
     * @throws EmbeddingsException when the answer is not one vector per input of the expected model
     *
     * @return list<list<float>>
     */
    private function request(array $inputs): array
    {
        $uri = mb_rtrim($this->baseUri, '/') . '/embeddings';
        $request_id = new ServiceRequestId('embeddings', $uri);

        try {
            $response = $this->httpClient->request(HttpRequest::post(
                uri: $uri,
                body: [
                    'model' => $this->model,
                    'input' => $inputs,
                    'encoding_format' => 'float',
                    ...($this->dimensions ? ['dimensions' => $this->dimensions] : []),
                ],
                headers: [...$this->httpHeaders, ...$request_id->header()],
            ));
        } catch (HttpException $exception) {
            $request_id->logFailure(ServiceRequestId::statusOf($exception));

            throw $request_id->exception($exception);
        }

        try {
            $answer = $response->json();
        } catch (JsonException) {
            // A 2xx page that is not JSON (a proxy's maintenance page): its body is not repeated, it may hold anything.
            throw new EmbeddingsException("[request_id {$request_id->value}] the embeddings server answered something that is not JSON");
        }

        return $this->vectors($answer, count($inputs));
    }

    /**
     * @param  array<string, mixed>  $answer
     * @return list<list<float>>
     */
    private function vectors(array $answer, int $expected): array
    {
        $answered = $answer['model'] ?? null;

        if (is_string($answered) && $answered !== '' && ! EmbeddingModelProfile::sameServiceModel($answered, $this->model)) {
            throw new EmbeddingsException("The embeddings server served model \"{$answered}\" but \"{$this->model}\" was requested");
        }

        $data = $answer['data'] ?? null;

        throw_unless(is_array($data), EmbeddingsException::class, 'The embeddings server returned an unexpected format: it carries no "data" list');
        throw_if($expected !== count($data), EmbeddingsException::class, 'Embeddings count mismatch: expected ' . $expected . ', got ' . count($data));

        $vectors = [];

        foreach (array_values($data) as $position => $item) {
            $index = is_array($item) && is_int($item['index'] ?? null) ? $item['index'] : $position;

            throw_if($index < 0 || $index >= $expected || isset($vectors[$index]), EmbeddingsException::class, 'The embeddings server returned a missing, repeated or out-of-range index');
            throw_unless(is_array($item) && is_array($item['embedding'] ?? null), EmbeddingsException::class, 'The embeddings server returned an invalid embedding vector');

            $vectors[$index] = array_map(static function (mixed $component): float {
                throw_unless(is_int($component) || is_float($component), EmbeddingsException::class, 'The embeddings server returned an invalid embedding component');

                return (float) $component;
            }, array_values($item['embedding']));
        }

        ksort($vectors);

        return array_values($vectors);
    }
}
