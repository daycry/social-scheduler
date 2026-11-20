<?php

namespace App\Http\Requests\Studio;

use Illuminate\Foundation\Http\FormRequest;

/** Pagina del historial de exportaciones al abrir un diseño (editor web y `GET designs/{design}` de la API). */
class EditorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['exports_page' => ['nullable', 'integer', 'min:1']];
    }

    public function exportsPage(): int
    {
        return (int) ($this->validated('exports_page') ?? 1);
    }
}
