<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta y edición de una campaña (C-03). La marca solo se informa al crear y debe ser del usuario. */
class CampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ];

        if ($this->isMethod('POST')) {
            $rules['brand_id'] = ['required', 'integer', Rule::exists('brands', 'id')->where('user_id', $this->user()?->id)];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return ['name' => 'nombre', 'color' => 'color', 'starts_on' => 'inicio', 'ends_on' => 'fin', 'brand_id' => 'marca'];
    }
}
