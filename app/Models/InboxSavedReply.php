<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

/** Respuesta guardada del inbox (T-20): plantilla de texto que se inserta en el cuadro de respuesta. */
class InboxSavedReply extends Model
{
    use BelongsToUser;

    public const TITLE_MAX = 60;

    public const BODY_MAX = 2200;

    protected $fillable = ['title', 'body'];
}
