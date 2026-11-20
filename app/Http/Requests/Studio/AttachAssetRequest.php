<?php

namespace App\Http\Requests\Studio;

use Illuminate\Foundation\Http\FormRequest;

/** Archivo de la biblioteca que se anade como recurso de la marca. */
class AttachAssetRequest extends FormRequest
{
    public const RULES = ['media_id' => ['required', 'integer', 'min:1']];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::RULES;
    }
}
