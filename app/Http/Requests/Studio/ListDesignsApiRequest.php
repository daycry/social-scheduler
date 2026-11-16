<?php

namespace App\Http\Requests\Studio;

/** Los filtros de la API, que ademas admiten `true`/`false` como texto en la query. */
class ListDesignsApiRequest extends ListDesignsRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['templates', 'archived'] as $key) {
            if (in_array($this->query($key), ['true', 'false'], true)) {
                $this->merge([$key => $this->query($key) === 'true']);
            }
        }
    }
}
