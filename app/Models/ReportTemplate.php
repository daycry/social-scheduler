<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Plantilla de informe guardada para una marca: bloques ordenados, rango relativo y comparacion. */
class ReportTemplate extends Model
{
    use BelongsToUser;

    protected $fillable = ['user_id', 'brand_id', 'name', 'blocks', 'range_days', 'compare'];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'range_days' => 'integer'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ReportSchedule::class);
    }
}
