<?php

namespace App\Models\Studio;

use App\Models\Brand;
use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;

class Design extends Model
{
    use BelongsToUser, SoftDeletes;

    protected $table = 'studio_designs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['document' => 'array', 'kit_reference' => 'array', 'revision' => 'integer', 'is_template' => 'boolean', 'width' => 'integer', 'height' => 'integer', 'page_count' => 'integer'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DesignFolder::class)->withoutGlobalScope(UserScope::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DesignRevision::class)->withoutGlobalScope(UserScope::class);
    }

    public function exports(): HasMany
    {
        return $this->hasMany(DesignExport::class)->withoutGlobalScope(UserScope::class);
    }

    /**
     * El binding ve los diseños propios y los compartidos con el usuario autenticado; quien no tiene acceso sigue
     * recibiendo 404. Las operaciones solo del propietario lo vuelven a comprobar en el servicio (`owned`).
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $userId = Auth::id();

        return parent::resolveRouteBindingQuery($query->withoutGlobalScope(UserScope::class), $value, $field)
            ->where(fn (Builder $scope) => $scope->where($this->qualifyColumn('user_id'), $userId)
                ->orWhereIn($this->qualifyColumn('id'), DesignShare::query()->where('user_id', $userId)->select('design_id')));
    }
}
