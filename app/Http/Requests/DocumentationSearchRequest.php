<?php

declare(strict_types=1);

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Override;

/**
 * The search text of a documentation search. Every accepted call costs an embedding, so a text
 * that is too short to mean anything, or too long to be a search, is refused before one is computed.
 */
final class DocumentationSearchRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:3', 'max:200'],
        ];
    }

    /**
     * The validated search text, trimmed.
     */
    public function searchText(): string
    {
        return (string) $this->validated('q');
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        $query = $this->input('q');

        if (is_string($query)) {
            $this->merge(['q' => mb_trim($query)]);
        }
    }
}
