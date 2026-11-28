<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    use BelongsToUser;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['body'];

    protected function casts(): array
    {
        return ['brand_id' => 'integer'];
    }
}
