<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Elemento de la cola de una autolista: una plantilla de la Biblioteca o un texto con media propios. */
class AutolistItem extends Model
{
    use BelongsToUser;

    protected $fillable = ['post_template_id', 'text', 'media', 'position', 'times_used', 'last_used_at'];

    protected function casts(): array
    {
        return ['media' => 'array', 'last_used_at' => 'datetime', 'times_used' => 'integer', 'position' => 'integer'];
    }

    public function autolist(): BelongsTo
    {
        return $this->belongsTo(Autolist::class)->withoutGlobalScope(UserScope::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PostTemplate::class, 'post_template_id')->withoutGlobalScope(UserScope::class);
    }
}
