<?php

namespace App\Http\Requests;

use App\Services\SavedTextService;
use Illuminate\Foundation\Http\FormRequest;

/** Alta y edición de un texto guardado (C-01a). La marca, si se informa, debe ser del usuario. */
class SavedTextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return SavedTextService::rules($this->user()?->id);
    }

    public function attributes(): array
    {
        return ['kind' => 'tipo', 'title' => 'título', 'body' => 'texto', 'brand_id' => 'marca'];
    }
}
