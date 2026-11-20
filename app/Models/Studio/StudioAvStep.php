<?php

namespace App\Models\Studio;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/** Ordered private work; the frequently updated export cursor contains no plan/checkpoint arrays. */
class StudioAvStep extends Model
{
    use MassPrunable;

    protected $table = 'studio_av_steps';

    /** Pasos de espacios de trabajo AV cuya ventana activa terminó hace más de `retention.studio_av_steps_days`. */
    public function prunable(): Builder
    {
        $days = (int) config('retention.studio_av_steps_days');
        if ($days <= 0) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->whereIn('workspace_id', StudioAvWorkspace::query()->select('id')->where('active_until', '<', now()->subDays($days)));
    }

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['workspace_id', 'generation', 'position', 'phase', 'item', 'source_id', 'target_phase', 'target_item', 'lease_id', 'result_lease_id'];

    protected function casts(): array
    {
        return ['workspace_id' => 'integer', 'position' => 'integer', 'item' => 'integer', 'target_item' => 'integer'];
    }

    public function descriptor(): array
    {
        $result = ['phase' => $this->phase, 'index' => $this->item];
        if ($this->phase === 'verify') {
            $result += ['source' => $this->source_id, 'target_phase' => $this->target_phase, 'target_index' => $this->target_item];
        }

        return $result;
    }
}
