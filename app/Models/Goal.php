<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Goal extends Model
{
    use BelongsToUser;

    public const KINDS = ['milestone' => 'Alcanzar un hito', 'drop' => 'Avisar de una caída', 'token_expiry' => 'Caducidad de una cuenta'];

    public const METRICS = ['followers' => 'Seguidores', 'engagement' => 'Engagement (%)'];

    public const LIMITS = ['min_integer' => 1, 'min_decimal' => 0.01, 'max' => ['milestone' => 1000000000000, 'drop' => 100, 'token_expiry' => 90]];

    protected $fillable = ['name', 'kind', 'metric', 'threshold', 'social_account_id', 'enabled', 'mail_enabled'];

    protected $attributes = ['enabled' => true, 'mail_enabled' => false];

    protected function casts(): array
    {
        return ['threshold' => 'float', 'enabled' => 'boolean', 'mail_enabled' => 'boolean', 'last_value' => 'float', 'last_evaluated_at' => 'datetime', 'last_expires_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id')->withoutGlobalScope(UserScope::class);
    }
}
