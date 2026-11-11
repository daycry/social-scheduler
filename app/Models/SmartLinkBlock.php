<?php

namespace App\Models;

use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bloque ordenado de un SmartLink. Sin user_id: cuelga de su SmartLink. Tipos: `link` (boton con URL), `header` (titulo),
 * `image` (imagen propia con enlace), `youtube` (video embebido por ID validado), `divider` (separador), `social` (iconos
 * de redes con sus URL), `email` (formulario de suscripcion propio) y `posts` (ultimas publicaciones publicadas de la marca).
 */
class SmartLinkBlock extends Model
{
    public const TYPE_LINK = 'link';

    public const TYPE_HEADER = 'header';

    public const TYPE_IMAGE = 'image';

    public const TYPE_YOUTUBE = 'youtube';

    public const TYPE_DIVIDER = 'divider';

    public const TYPE_SOCIAL = 'social';

    public const TYPE_EMAIL = 'email';

    public const TYPE_POSTS = 'posts';

    public const TYPES = [self::TYPE_LINK, self::TYPE_HEADER, self::TYPE_IMAGE, self::TYPE_YOUTUBE, self::TYPE_DIVIDER, self::TYPE_SOCIAL, self::TYPE_EMAIL, self::TYPE_POSTS];

    /** Redes de los iconos sociales (el icono es texto accesible: no se cargan imagenes de terceros). */
    public const SOCIAL_NETWORKS = ['instagram', 'facebook', 'youtube', 'tiktok', 'bluesky', 'threads', 'x', 'linkedin', 'web'];

    protected $fillable = ['position', 'type', 'label', 'url', 'media_id', 'data', 'visible_from', 'visible_until'];

    protected function casts(): array
    {
        return ['data' => 'array', 'visible_from' => 'datetime', 'visible_until' => 'datetime'];
    }

    public function smartLink(): BelongsTo
    {
        return $this->belongsTo(SmartLink::class)->withoutGlobalScopes();
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_id')->withoutGlobalScope(UserScope::class);
    }

    /** ¿Se muestra a los visitantes ahora? Respeta la visibilidad programada (UTC). */
    public function isVisibleAt(\DateTimeInterface $at): bool
    {
        return ($this->visible_from === null || $this->visible_from <= $at) && ($this->visible_until === null || $this->visible_until > $at);
    }

    public function scopeVisibleNow(Builder $q): Builder
    {
        return $q->where(fn (Builder $w) => $w->whereNull('visible_from')->orWhere('visible_from', '<=', now()))
            ->where(fn (Builder $w) => $w->whereNull('visible_until')->orWhere('visible_until', '>', now()));
    }

    /** URLs de destino del bloque (las unicas a las que `go` puede redirigir): una por boton, imagen o video; N por iconos sociales. */
    public function targets(): array
    {
        return match ($this->type) {
            self::TYPE_LINK, self::TYPE_IMAGE, self::TYPE_YOUTUBE => $this->url ? [$this->url] : [],
            self::TYPE_SOCIAL => array_values(array_filter(array_map(fn ($i) => $i['url'] ?? null, (array) ($this->data['items'] ?? [])))),
            default => [],
        };
    }
}
