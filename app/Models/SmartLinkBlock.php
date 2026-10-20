<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bloque ordenado de un SmartLink: `link` (con URL http/https) o `header` (solo texto). Sin user_id: cuelga de su SmartLink. */
class SmartLinkBlock extends Model
{
    public const TYPE_LINK = 'link';

    public const TYPE_HEADER = 'header';

    protected $fillable = ['position', 'type', 'label', 'url'];

    public function smartLink(): BelongsTo
    {
        return $this->belongsTo(SmartLink::class)->withoutGlobalScopes();
    }
}
