<?php

namespace App\Http\Requests\Studio;

use Illuminate\Foundation\Http\FormRequest;

/** Nombre de una carpeta de diseños al crearla o renombrarla. */
class FolderNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255']];
    }
}
