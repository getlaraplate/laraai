<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Embeddings;

/**
 * What a probe of an embeddings server found: the model the answer to the probe names, the model `GET /info`
 * names where the server has that route (Text Embeddings Inference), the norm of the probe vector and the
 * largest client batch the server accepts (also from `/info`). Each is null when the server does not say.
 */
final readonly class EmbeddingInspection
{
    /**
     * How far from 1 the norm of a vector of a normalising profile may be.
     */
    public const float NORM_TOLERANCE = 1e-3;

    public function __construct(
        public ?string $probeModel,
        public ?string $infoModel,
        public float $norm,
        public ?int $maxClientBatchSize,
    ) {}

    /**
     * The model to check and where it comes from: the probe's answer first, `/info` as fallback; null when
     * neither names one.
     *
     * @return array{model: string, source: string}|null
     */
    public function reportedModel(): ?array
    {
        if ($this->probeModel !== null) {
            return ['model' => $this->probeModel, 'source' => '/v1/embeddings'];
        }

        if ($this->infoModel !== null) {
            return ['model' => $this->infoModel, 'source' => '/info'];
        }

        return null;
    }

    /**
     * Why a model the server names is not the profile's, or null when every name given agrees with it (or none is given).
     * The two witnesses must agree: a server whose `/info` and answer differ is not running one model.
     */
    public function modelConflict(EmbeddingModelProfile $profile): ?string
    {
        foreach ([[$this->probeModel, '/v1/embeddings'], [$this->infoModel, '/info']] as [$model, $source]) {
            if ($model !== null && ! EmbeddingModelProfile::sameServiceModel($model, $profile->serviceModel)) {
                return "the embedding server {$source} reports model \"{$model}\" but the profile expects \"{$profile->serviceModel}\"";
            }
        }

        return null;
    }

    /**
     * Why the probe vector is not normalised although the profile says its vectors are, or null: a server started
     * without normalisation would store vectors of another scale under the profile's key.
     */
    public function normProblem(EmbeddingModelProfile $profile): ?string
    {
        if (! $profile->normalize || abs($this->norm - 1.0) <= self::NORM_TOLERANCE) {
            return null;
        }

        return sprintf('the embedding server returns vectors that are not normalised (norm %s) but the profile "%s" declares normalize', number_format($this->norm, 4, '.', ''), $profile->key);
    }
}
