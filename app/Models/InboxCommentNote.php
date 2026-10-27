<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nota interna de un comentario del inbox (C-04d): texto plano que nunca se publica ni se envía a la red. */
class InboxCommentNote extends Model
{
    use BelongsToUser, HasFactory;

    /** Tope de caracteres de una nota. */
    public const BODY_MAX = 2000;

    protected $fillable = ['body'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(InboxComment::class, 'inbox_comment_id')->withoutGlobalScope(UserScope::class);
    }
}
