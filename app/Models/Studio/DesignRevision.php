<?php

namespace App\Models\Studio;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignRevision extends Model
{
    use BelongsToUser;

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
