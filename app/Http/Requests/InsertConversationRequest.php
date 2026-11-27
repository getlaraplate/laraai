<?php

declare(strict_types=1);

namespace Modules\AI\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Modules\AI\Services\Assistance\AssistantControlPlaneData;

class InsertConversationRequest extends FormRequest
{
    /**
     * @return array<string, list<string|Closure>>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'], // conversation title
            'memory_enabled' => ['sometimes', 'boolean'], // whether the assistant reads the earlier turns
            'system_message' => ['prohibited'],
            'system_prompt' => ['prohibited'],
            'profile' => ['prohibited'],
            'user_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'permissions' => ['prohibited'],
            'roles' => ['prohibited'],
            'tools' => ['prohibited'],
            'metadata' => [
                'nullable',
                'array',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_array($value) && AssistantControlPlaneData::containsForbiddenKey($value)) {
                        $fail('The metadata contains a prohibited assistant control field.');
                    }
                },
            ],
        ];
    }

    // /**
    //  * Determine if the user is authorized to make this request.
    //  */
    // public function authorize(): bool
    // {
    //     return true;
    // }
}
