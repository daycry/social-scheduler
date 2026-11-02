<?php

namespace App\Http\Requests;

use App\Connectors\Network;
use App\Enums\PostStatus;
use App\Models\CalendarView;
use App\Services\CalendarService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta y edición de una vista guardada del calendario (C-03a). La marca debe ser del usuario; red, estado y vista, valores conocidos. */
class CalendarViewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $route = $this->route('view');
        $ignore = $route instanceof CalendarView ? $route->id : $route;
        $creating = $this->isMethod('POST');

        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120', Rule::unique('calendar_views', 'name')->where('user_id', $this->user()?->id)->ignore($ignore)],
            'filters' => ['nullable', 'array'],
            'filters.brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('user_id', $this->user()?->id)],
            'filters.network' => ['nullable', 'string', Rule::in(array_map(fn (Network $n) => $n->value, Network::cases()))],
            'filters.status' => ['nullable', 'string', Rule::in(array_map(fn (PostStatus $s) => $s->value, PostStatus::cases()))],
            'filters.view' => ['nullable', 'string', Rule::in(CalendarService::VIEWS)],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'nombre', 'filters.brand_id' => 'marca', 'filters.network' => 'red', 'filters.status' => 'estado', 'filters.view' => 'vista', 'is_default' => 'vista por defecto'];
    }
}
