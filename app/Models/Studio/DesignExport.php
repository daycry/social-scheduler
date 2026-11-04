<?php

namespace App\Models\Studio;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignExport extends Model
{
    use BelongsToUser;

    protected $table = 'studio_design_exports';

    protected $guarded = ['id'];

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'pages' => 'array', 'media_ids' => 'array', 'options' => 'array'];
    }

    public function design(): BelongsTo
    {
        return $this->belongsTo(Design::class)->withoutGlobalScope(UserScope::class)->withTrashed();
    }
}
