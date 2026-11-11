<?php

namespace App\Models;

use App\Exceptions\PostValidationException;
use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** Serie recurrente de publicaciones (T-19). Las ocurrencias son posts reales generados por `SeriesService`. */
class PostSeries extends Model
{
    use BelongsToUser;

    public const ACTIVE = 'activa';

    public const CANCELLED = 'cancelada';

    protected $table = 'post_series';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weekdays' => 'array', 'payload' => 'array', 'starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d', 'cancelled_at' => 'datetime', 'last_error_at' => 'datetime', 'max_occurrences' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saved(fn (PostSeries $s) => $s->syncMediaIndex());
    }

    /**
     * Indice relacional (`post_series_media`, FK restrict) de la media que usa el contenido de la serie mientras esta activa: media comun, y
     * media y portada de cada destino. El JSON `payload` sigue siendo el contenido; esto solo da la integridad referencial (T-06).
     */
    public function syncMediaIndex(): void
    {
        if (! $this->wasRecentlyCreated && ! $this->wasChanged(['payload', 'status'])) {
            return;
        }
        $ids = [];
        if ($this->status === self::ACTIVE) {
            $payload = (array) $this->payload;
            $ids = array_map('intval', (array) ($payload['media'] ?? []));
            foreach ((array) ($payload['targets'] ?? []) as $t) {
                foreach ((array) ($t['media'] ?? []) as $m) {
                    $ids[] = (int) ($m['id'] ?? 0);
                    $ids[] = (int) ($m['cover_id'] ?? 0);
                }
            }
            $ids = array_values(array_unique(array_filter($ids)));
            $this->assertMediaExists($ids);
        }
        DB::transaction(function () use ($ids) {
            DB::table('post_series_media')->where('post_series_id', $this->id)->delete();
            if ($ids !== []) {
                DB::table('post_series_media')->insert(array_map(fn ($id) => ['post_series_id' => $this->id, 'media_asset_id' => $id], $ids));
            }
        });
    }

    /** @param  list<int>  $ids */
    private function assertMediaExists(array $ids): void
    {
        $found = $ids === [] ? [] : MediaAsset::withoutUserScope()->where('user_id', $this->user_id)->whereIn('id', $ids)->pluck('id')->all();
        if (count($found) !== count($ids)) {
            throw new PostValidationException(['media' => ['Algun archivo de la serie no existe: '.implode(', ', array_diff($ids, $found)).'.']]);
        }
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }

    public function occurrences(): HasMany
    {
        return $this->hasMany(PostSeriesOccurrence::class, 'series_id');
    }
}
