<?php

declare(strict_types=1);

namespace Modules\AI\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AI\Services\Assistance\AssistantCapabilities;
use Override;

/**
 * The body of `GET /app/ai/capabilities`: `enabled`, `configured` and the `features` a client may rely on.
 *
 * @property AssistantCapabilities $resource
 */
final class AiCapabilitiesResource extends JsonResource
{
    /**
     * @param  list<array{entity: string, operation: string, kind: string, requires_approval: bool}>  $actions  what the assistant may do for the signed-in user
     */
    public function __construct(AssistantCapabilities $resource, private readonly array $actions = [])
    {
        parent::__construct($resource);
    }

    /**
     * `actions` is what the assistant may do for the person who asks: each entity and operation the
     * operator opted in and the person is permitted to perform, `kind` being `read` or `write`, and
     * `requires_approval` saying that a write the person confirms is then sent for a vote.
     *
     * @return array{enabled: bool, configured: bool, features: array{help: bool, proposals: bool, writes: bool, streaming: bool}, actions: list<array{entity: string, operation: string, kind: string, requires_approval: bool}>}
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return [...$this->resource->toArray(), 'actions' => $this->actions];
    }
}
