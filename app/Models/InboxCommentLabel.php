<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

/** Etiqueta libre de un comentario del inbox (T-20). */
class InboxCommentLabel extends Model
{
    use BelongsToUser;

    protected $fillable = ['label'];
}
