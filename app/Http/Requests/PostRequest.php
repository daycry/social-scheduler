<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la propiedad de la marca/publicacion se comprueba en el controlador (Policies)
    }

    public function rules(): array
    {
        return [
            'text' => ['nullable', 'string', 'max:70000'],
            // Hora local de la marca ("2026-11-02T10:00"); la zona la aplica PostService.
            'scheduled_at' => ['nullable', 'string', 'max:40'],
            'schedule' => ['required', 'boolean'],
            'media' => ['nullable', 'array', 'max:35'],
            'media.*' => ['integer'],
            'targets' => ['nullable', 'array', 'max:40'],
            'targets.*.account_id' => ['required', 'integer'],
            'targets.*.content_type' => ['required', 'string', 'max:40'],
            'targets.*.options' => ['nullable', 'array'],
            'targets.*.options.share_to_feed' => ['nullable', 'boolean'],
            'targets.*.options.link' => ['nullable', 'url', 'max:2000'],
        ];
    }
}
