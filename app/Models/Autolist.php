<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Autolista (PA-09): cola de contenido + franjas semanales que genera publicaciones programadas. */
class Autolist extends Model
{
    use BelongsToUser;

    protected $fillable = ['name', 'active', 'recycle', 'targets', 'slots', 'cursor', 'last_generated_at'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'recycle' => 'boolean',
            'targets' => 'array',
            'slots' => 'array',
            'cursor' => 'integer',
            'last_generated_at' => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(AutolistItem::class)->withoutGlobalScope(UserScope::class)->orderBy('position')->orderBy('id');
    }

    public function slotLog(): HasMany
    {
        return $this->hasMany(AutolistSlot::class)->withoutGlobalScope(UserScope::class);
    }
}
