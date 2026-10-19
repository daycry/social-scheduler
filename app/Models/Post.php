<?php

namespace App\Models;

use App\Enums\PostStatus;
use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Publicacion (texto + media + fecha) que se envia a una o varias cuentas mediante sus `PostTarget`.
 * `scheduled_at` va siempre en UTC; la zona horaria es la de la marca. `status` es el agregado de los
 * targets (los estados reales viven en cada target).
 *
 * Las relaciones internas quitan el UserScope: se llega a ellas desde un padre ya acotado al usuario
 * (o desde un job que opta explicitamente por withoutUserScope), y sin usuario autenticado el scope
 * las dejaria vacias.
 */
class Post extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = ['text', 'scheduled_at', 'status'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'status' => PostStatus::class,
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class)->withoutGlobalScope(UserScope::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'post_media')
            ->withoutGlobalScope(UserScope::class)->withPivot('position')->orderByPivot('position');
    }

    /** Fecha programada en la zona horaria de la marca. */
    public function scheduledAtLocal(): ?CarbonInterface
    {
        return $this->scheduled_at?->copy()->setTimezone($this->brand->timezone ?: 'UTC');
    }

    /** Recalcula el estado agregado a partir de los targets. */
    public function syncStatus(): static
    {
        $statuses = $this->targets()->pluck('status')->map(fn ($s) => $s instanceof PostStatus ? $s : PostStatus::from($s));

        $this->status = match (true) {
            $statuses->isEmpty() => PostStatus::Draft,
            $statuses->contains(fn (PostStatus $s) => $s->isInFlight()) => PostStatus::Publishing,
            $statuses->contains(PostStatus::Scheduled) => PostStatus::Scheduled,
            $statuses->contains(PostStatus::Draft) => PostStatus::Draft,
            $statuses->contains(PostStatus::Failed) => PostStatus::Failed,
            default => PostStatus::Published,
        };
        $this->save();

        return $this;
    }
}
