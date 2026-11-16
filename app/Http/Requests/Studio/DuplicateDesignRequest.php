<?php

namespace App\Http\Requests\Studio;

use Illuminate\Foundation\Http\FormRequest;

/** Nombre opcional de la copia de un diseño. */
class DuplicateDesignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['nullable', 'string', 'max:255']];
    }
}
