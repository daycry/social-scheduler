<?php

namespace App\Models;

use App\Connectors\Network;
use App\Enums\ContentType;
use App\Enums\FirstCommentStatus;
use App\Enums\PostStatus;
use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use App\Support\TargetMedia;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Destino de una publicacion: una cuenta + un tipo de contenido. Es la unidad que se publica, reintenta
 * y consulta (unicidad por post + cuenta + tipo). `social_account_id` es nullable sin cascade: borrar la
 * cuenta no borra la publicacion pendiente.
 */
class PostTarget extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'social_account_id', 'network', 'content_type', 'status', 'options', 'text', 'external_id', 'external_url',
        'external_reference', 'progress', 'status_note', 'attempts', 'error_code', 'error_message', 'started_at', 'next_attempt_at', 'published_at',
        'media', 'has_own_media', 'manual', 'manual_notified_at', 'first_comment', 'first_comment_status', 'first_comment_attempts', 'first_comment_external_id', 'first_comment_error', 'first_comment_at', 'first_comment_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'network' => Network::class,
            'content_type' => ContentType::class,
            'status' => PostStatus::class,
            'options' => 'array',
            'manual' => 'boolean',
            'manual_notified_at' => 'datetime',
            'has_own_media' => 'boolean',
            'progress' => 'array',
            'attempts' => 'integer',
            'first_comment_status' => FirstCommentStatus::class,
            'first_comment_attempts' => 'integer',
            'first_comment_at' => 'datetime',
            'first_comment_sent_at' => 'datetime',
            'started_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /** Media propia escrita y aun no volcada a `post_target_media` (se vuelca al guardar); `false` = nada pendiente. */
    private array|false|null $pendingMedia = false;

    protected $with = ['mediaItems'];

    protected $hidden = ['mediaItems', 'has_own_media'];

    protected $appends = ['media'];

    protected static function booted(): void
    {
        static::saved(fn (PostTarget $t) => $t->flushMedia());
    }

    /**
     * Media propia del destino (T-17, relacional desde T-06): `null` = hereda la comun; lista `{id, alt, cover_id}` ordenada = propia
     * (`[]` es valida). El contrato visible no cambia; se guarda en `post_target_media` con FK restrict hacia `media_assets`.
     */
    protected function media(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->pendingMedia !== false) {
                    return $this->pendingMedia;
                }
                if (! $this->has_own_media) {
                    return null;
                }

                return $this->mediaItems->sortBy('position')->map(fn (PostTargetMedia $m) => [
                    'id' => (int) $m->media_asset_id, 'alt' => $m->alt, 'cover_id' => $m->cover_id === null ? null : (int) $m->cover_id,
                ])->values()->all();
            },
            set: function ($value) {
                $this->pendingMedia = is_array($value) ? TargetMedia::normalize($value) : null;

                return ['has_own_media' => $this->pendingMedia !== null];
            },
        );
    }

    public function mediaItems(): HasMany
    {
        return $this->hasMany(PostTargetMedia::class)->orderBy('position');
    }

    /** Vuelca la media propia pendiente a `post_target_media` (sustituye la lista entera, en una transaccion). */
    private function flushMedia(): void
    {
        if ($this->pendingMedia === false) {
            return;
        }
        $list = $this->pendingMedia ?? [];
        $this->pendingMedia = false;

        DB::transaction(function () use ($list) {
            PostTargetMedia::query()->where('post_target_id', $this->id)->delete();
            if ($list !== []) {
                PostTargetMedia::query()->insert(array_map(fn (array $m, int $i) => [
                    'post_target_id' => $this->id, 'media_asset_id' => $m['id'], 'cover_id' => $m['cover_id'], 'alt' => $m['alt'], 'position' => $i,
                ], $list, array_keys($list)));
            }
        });
        $this->unsetRelation('mediaItems');
        $this->load('mediaItems');
    }

    /**
     * Texto que se publica en este destino (ADR-005, O1): el propio si lo tiene (`text` no nulo, tambien la cadena vacia) y, si no,
     * el de la plantilla del post (`null` = vinculado). Es la unica funcion que lo resuelve.
     */
    public function effectiveText(): ?string
    {
        return $this->text ?? $this->post?->text;
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withoutGlobalScope(UserScope::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id')->withoutGlobalScope(UserScope::class);
    }

    public function attemptLog(): HasMany
    {
        return $this->hasMany(PublishAttempt::class)->withoutGlobalScope(UserScope::class)->orderBy('attempt_no');
    }
}
