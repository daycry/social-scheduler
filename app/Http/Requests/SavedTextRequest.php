<?php

namespace App\Http\Requests;

use App\Models\SavedText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta y edición de un texto guardado (C-01a). La marca, si se informa, debe ser del usuario. */
class SavedTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(SavedText::KINDS)],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:5000'],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('user_id', $this->user()?->id)],
        ];
    }

    public function attributes(): array
    {
        return ['kind' => 'tipo', 'title' => 'título', 'body' => 'texto', 'brand_id' => 'marca'];
    }
}
