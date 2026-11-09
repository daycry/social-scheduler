<?php

namespace App\Models\Studio;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class StudioFont extends Model
{
    use BelongsToUser;

    protected $table = 'studio_fonts';

    protected $guarded = ['id'];

    protected $hidden = ['user_id', 'disk', 'path', 'source_sha256'];

    protected function casts(): array
    {
        return ['weight' => 'integer', 'size' => 'integer', 'archived_at' => 'datetime'];
    }
}
