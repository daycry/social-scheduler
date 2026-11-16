<?php

namespace App\Http\Requests\Studio;

use Illuminate\Foundation\Http\FormRequest;

/** Paginacion del historial de exportaciones de un diseño. */
class ListExportsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']];
    }
}
