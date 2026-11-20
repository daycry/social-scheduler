<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Webhook extends Model
{
    use BelongsToUser;

    public const EVENTS = ['post.published' => 'Publicación completada', 'post.failed' => 'Publicación fallida', 'comment.created' => 'Comentario nuevo'];

    protected $fillable = ['name', 'url', 'events', 'secret', 'enabled'];

    protected $hidden = ['secret'];

    protected $attributes = ['enabled' => true];

    protected function casts(): array
    {
        return ['brand_id' => 'integer', 'events' => 'array', 'enabled' => 'boolean', 'secret' => 'encrypted'];
    }

    public function latestDelivery(): HasOne
    {
        return $this->hasOne(WebhookDelivery::class)->withoutGlobalScope(UserScope::class)->latestOfMany();
    }
}
