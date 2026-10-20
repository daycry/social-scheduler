<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'timezone' => ['required', 'timezone:all'],
            // Solo imagenes listas del propio usuario.
            'avatar_media_id' => ['nullable', 'integer', Rule::exists('media_assets', 'id')->where('user_id', $this->user()?->id)->where('kind', 'image')],
        ];
    }
}
