<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sitio multipagina de una marca (T-15). `draft` es lo que se edita; `published` es la instantanea inmutable que sirve
 * `/s/{slug}` hasta que se vuelve a publicar. Las relaciones internas quitan el UserScope: la ruta publica las lee sin sesion.
 */
class Site extends Model
{
    use BelongsToUser;

    public const ACCESS_PUBLIC = 'public';

    public const ACCESS_PASSWORD = 'password';

    public const ACCESS_TOKEN = 'token';

    public const ACCESS_MODES = [self::ACCESS_PUBLIC, self::ACCESS_PASSWORD, self::ACCESS_TOKEN];

    protected $guarded = ['id'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return ['draft' => 'array', 'published' => 'array', 'is_published' => 'boolean', 'noindex' => 'boolean', 'published_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(SiteToken::class)->orderByDesc('id');
    }

    public function url(?string $page = null): string
    {
        return url('/s/'.$this->slug.($page ? '/'.$page : ''));
    }
}
