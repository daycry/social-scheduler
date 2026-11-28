<?php

namespace App\Http\Requests\Studio;

use Illuminate\Foundation\Http\FormRequest;

/** Carpeta destino de un diseño (`null` = fuera de toda carpeta). */
class MoveDesignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['folder_id' => ['present', 'nullable', 'integer', 'min:1']];
    }
}
