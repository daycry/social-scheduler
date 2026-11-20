<?php

namespace App\Http\Requests\Studio;

use App\Support\CurrentBrand;
use App\Support\CursorPage;
use Illuminate\Foundation\Http\FormRequest;

/** Filtros del listado de diseños de una marca (web y API). */
class ListDesignsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // La portada sin marca muestra los diseños compartidos y no aplica filtros del listado de marca.
        if ($this->routeIs('studio.index') && CurrentBrand::brands($this)->isEmpty()) {
            return [];
        }

        return ['query' => ['nullable', 'string', 'max:120'], 'folder_id' => ['nullable', 'integer', 'min:1'],
            'templates' => ['nullable', 'boolean'], 'archived' => ['nullable', 'boolean']] + CursorPage::RULES;
    }
}
