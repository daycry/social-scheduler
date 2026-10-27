<?php

namespace App\Models;

use App\Connectors\Network;
use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Comentario de una publicacion propia en Instagram/Facebook (PA-10), o respuesta nuestra (`is_own`). */
class InboxComment extends Model
{
    use BelongsToUser, HasFactory;

    protected $fillable = [
        'network', 'external_id', 'parent_external_id', 'post_external_id', 'author_name', 'text',
        'is_own', 'hidden', 'commented_at', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'network' => Network::class,
            'is_own' => 'boolean',
            'hidden' => 'boolean',
            'commented_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id')->withoutGlobalScope(UserScope::class);
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(PostTarget::class, 'post_target_id')->withoutGlobalScope(UserScope::class);
    }

    /** Notas internas (C-04d), de la más antigua a la más reciente; sobreviven al upsert del inbox. */
    public function notes(): HasMany
    {
        return $this->hasMany(InboxCommentNote::class)->withoutGlobalScope(UserScope::class)->orderBy('id');
    }
}
