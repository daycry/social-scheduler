<?php

namespace App\Models;

use App\Connectors\Network;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Destino de una publicacion: una cuenta + un tipo de contenido. Es la unidad que se publica, reintenta
 * y consulta (unicidad por post + cuenta + tipo). `social_account_id` es nullable sin cascade: borrar la
 * cuenta no borra la publicacion pendiente.
 */
class PostTarget extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'social_account_id', 'network', 'content_type', 'status', 'options', 'external_id', 'external_url',
        'external_reference', 'progress', 'status_note', 'attempts', 'error_code', 'error_message', 'started_at', 'next_attempt_at', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'network' => Network::class,
            'content_type' => ContentType::class,
            'status' => PostStatus::class,
            'options' => 'array',
            'progress' => 'array',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'published_at' => 'datetime',
        ];
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
