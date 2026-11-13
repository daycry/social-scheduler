<?php

namespace App\Models;

use App\Exceptions\PostValidationException;
use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** Elemento de la cola de una autolista: una plantilla de la Biblioteca o un texto con media propios. */
class AutolistItem extends Model
{
    use BelongsToUser;

    protected $fillable = ['post_template_id', 'text', 'media', 'position', 'times_used', 'last_used_at'];

    protected function casts(): array
    {
        return ['media' => 'array', 'last_used_at' => 'datetime', 'times_used' => 'integer', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saved(fn (AutolistItem $i) => $i->syncMediaIndex());
    }

    /** Indice relacional (`autolist_item_media`, FK restrict) de la media propia del elemento; el JSON `media` sigue siendo el contenido (T-06). */
    public function syncMediaIndex(): void
    {
        if (! $this->wasRecentlyCreated && ! $this->wasChanged('media')) {
            return;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->media))));
        $found = $ids === [] ? [] : MediaAsset::withoutUserScope()->where('user_id', $this->user_id)->whereIn('id', $ids)->pluck('id')->all();
        if (count($found) !== count($ids)) {
            throw new PostValidationException(['media' => ['Algun archivo no existe: '.implode(', ', array_diff($ids, $found)).'.']]);
        }
        DB::transaction(function () use ($ids) {
            DB::table('autolist_item_media')->where('autolist_item_id', $this->id)->delete();
            if ($ids !== []) {
                DB::table('autolist_item_media')->insert(array_map(fn ($id) => ['autolist_item_id' => $this->id, 'media_asset_id' => $id], $ids));
            }
        });
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
