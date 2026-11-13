<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;

/**
 * Poda por antigüedad (A-08): `model:prune` borra por lotes las filas más viejas que `config/retention.php`.
 * El modelo declara `retentionKey()` (clave de config en días) y, si hace falta, `pruneScope()` para acotar qué filas son podables.
 */
trait PrunesByAge
{
    use MassPrunable;

    abstract protected static function retentionKey(): string;

    protected static function pruneColumn(): string
    {
        return 'created_at';
    }

    /** Acota las filas podables (p. ej. solo estados terminales). */
    protected static function pruneScope(Builder $query): Builder
    {
        return $query;
    }

    public function prunable(): Builder
    {
        $days = (int) config('retention.'.static::retentionKey());
        $query = static::withoutGlobalScopes();
        if ($days <= 0) {
            return $query->whereRaw('1 = 0');
        }

        return static::pruneScope($query->where($this->qualifyColumn(static::pruneColumn()), '<', now()->subDays($days)));
    }
}
