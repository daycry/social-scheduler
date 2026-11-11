<?php

namespace App\Models\Studio;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Events\ModelsPruned;
use Illuminate\Support\Facades\DB;

class DesignRevision extends Model
{
    use BelongsToUser;
    use MassPrunable;

    protected $table = 'studio_design_revisions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['document' => 'array', 'kit_reference' => 'array', 'revision' => 'integer', 'autosave' => 'boolean'];
    }

    /**
     * Retención (A-07): de cada diseño se conservan las `keep_last` revisiones más recientes y la última de cada día hasta
     * `daily_days`; el resto, y todo lo anterior a ese plazo salvo las recientes, se poda. Las filas de `studio_font_usages`
     * de lo podado caen por `ON DELETE CASCADE`, de modo que una tipografía solo usada por ese historial vuelve a poder borrarse.
     */
    public function prunable(): Builder
    {
        $keepLast = max(1, (int) config('retention.design_revisions.keep_last'));
        $dailyDays = (int) config('retention.design_revisions.daily_days');
        $table = $this->getTable();

        // Las sustituidas no ocupan plaza en el historial visible: ni entre las ultimas N ni entre las diarias.
        $recent = DB::table(DB::raw("(select id, row_number() over (partition by design_id order by revision desc) as rn from {$table} where superseded_at is null) as ranked"))
            ->where('rn', '<=', $keepLast)->select('id');
        $dailyLast = DB::table(DB::raw("(select max(id) as id from {$table} where superseded_at is null and created_at >= ? group by design_id, date(created_at)) as daily"))
            ->setBindings([now()->subDays($dailyDays)->toDateTimeString()])->select('id');

        $grace = max(0, (int) config('retention.design_revisions.superseded_hours', 24));

        return static::withoutGlobalScopes()
            ->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('superseded_at')->whereNotIn('id', $recent)->whereNotIn('id', $dailyLast))
                ->orWhere('superseded_at', '<', now()->subHours($grace)))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('studio_design_exports')
                ->whereColumn('studio_design_exports.design_id', "{$table}.design_id")->whereColumn('studio_design_exports.revision', "{$table}.revision")
                ->where('studio_design_exports.status', '!=', 'ready'));
    }

    /** Calcula una sola vez qué revisiones se podan (la ventana por diseño no se recalcula en cada lote) y las borra por lotes. */
    public function pruneAll(int $chunkSize = 1000): int
    {
        $total = 0;
        foreach ($this->prunable()->pluck('id')->chunk($chunkSize) as $ids) {
            $total += static::withoutGlobalScopes()->whereIn('id', $ids)->delete();
            event(new ModelsPruned(static::class, $total));
        }

        return $total;
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class)->withoutGlobalScope(UserScope::class)->withTrashed();
    }
}
