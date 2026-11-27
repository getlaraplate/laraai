<?php

declare(strict_types=1);

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Whether the assistant may read the earlier turns of one conversation before answering.
 */
class UpdateConversationMemoryRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'memory_enabled' => ['required', 'boolean'],
        ];
    }
}
