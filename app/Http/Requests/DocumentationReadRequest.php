<?php

declare(strict_types=1);

namespace Modules\AI\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The reference and the optional section of a documentation read. Only the shape of the input is
 * checked here: a reference that is not a document key, for example the label an item carried before
 * the first reindex, is a document that cannot be read (404), not an invalid request.
 */
final class DocumentationReadRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:200'],
            'section' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function reference(): string
    {
        return $this->string('reference')->toString();
    }

    public function section(): ?int
    {
        return $this->filled('section') ? $this->integer('section') : null;
    }
}
