<?php

declare(strict_types=1);

namespace Modules\AI\Ai\Providers\Models;

use Illuminate\Support\Facades\Http;
use Modules\AI\Enums\ModelCapability;
use Override;
use UnexpectedValueException;

/**
 * Every Claude model chats and calls tools; vision comes from `capabilities.image_input`, and
 * is assumed when the API returns no capabilities for a model.
 */
final readonly class AnthropicModelLister implements ModelLister
{
    public function __construct(private string $apiKey) {}

    #[Override]
    public function list(): array
    {
        $models = [];
        $after_id = null;

        do {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::REQUEST_TIMEOUT)
                ->withHeaders(['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01'])
                ->get('https://api.anthropic.com/v1/models', array_filter(['limit' => 1000, 'after_id' => $after_id]))
                ->throw();

            $data = $response->json('data');

            if (! is_array($data)) {
                throw new UnexpectedValueException('Anthropic answered without a model list.');
            }

            foreach ($data as $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : null;

                if (is_string($id) && $id !== '') {
                    $models[] = new ListedModel($id, self::capabilities($item));
                }
            }

            $last_id = $response->json('last_id');
            $after_id = $response->json('has_more') === true && is_string($last_id) && $last_id !== '' ? $last_id : null;
        } while ($after_id !== null);

        return $models;
    }

    /**
     * @param  array<array-key, mixed>  $item  one model of the decoded list
     * @return list<ModelCapability>
     */
    private static function capabilities(array $item): array
    {
        $capabilities = [ModelCapability::Chat, ModelCapability::Tools];
        $image_input = data_get($item, 'capabilities.image_input.supported');

        if (($item['capabilities'] ?? null) === null || $image_input === true) {
            $capabilities[] = ModelCapability::Vision;
        }

        return $capabilities;
    }
}
