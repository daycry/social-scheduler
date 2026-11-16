<?php

namespace App\Http\Requests\Studio;

use Illuminate\Foundation\Http\FormRequest;

/** Archivo de la biblioteca que se anade como recurso de la marca. */
class AttachAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['media_id' => ['required', 'integer', 'min:1']];
    }
}
