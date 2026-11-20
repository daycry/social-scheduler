<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class GoalAlertEvent extends Model
{
    use BelongsToUser;

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
