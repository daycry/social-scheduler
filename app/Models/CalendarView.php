<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Vista guardada del calendario (C-03a): un nombre y los filtros de un solo valor (`brand_id`, `network`, `status` y `view`
 * —semana, mes o lista—). Como mucho una por usuario es la vista por defecto.
 */
class CalendarView extends Model
{
    use BelongsToUser, HasFactory;

    /** Claves de `filters` que una vista puede guardar. */
    public const FILTER_KEYS = ['brand_id', 'network', 'status', 'view'];

    protected $fillable = ['name', 'filters', 'is_default'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'is_default' => 'boolean'];
    }
}
