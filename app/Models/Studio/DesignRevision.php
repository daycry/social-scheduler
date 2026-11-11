<?php

namespace App\Models\Studio;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class DesignRevision extends Model
{
    use BelongsToUser;
    use MassPrunable;

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

        $recent = DB::table(DB::raw("(select id, row_number() over (partition by design_id order by revision desc) as rn from {$table}) as ranked"))
            ->where('rn', '<=', $keepLast)->select('id');
        $dailyLast = DB::table(DB::raw("(select max(id) as id from {$table} where created_at >= ? group by design_id, date(created_at)) as daily"))
            ->setBindings([now()->subDays($dailyDays)->toDateTimeString()])->select('id');

        return static::withoutGlobalScopes()->whereNotIn('id', $recent)->whereNotIn('id', $dailyLast);
    }

    protected $table = 'studio_design_revisions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['document' => 'array', 'kit_reference' => 'array', 'revision' => 'integer'];
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class)->withoutGlobalScope(UserScope::class)->withTrashed();
    }
}
