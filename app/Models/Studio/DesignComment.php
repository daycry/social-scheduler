<?php

namespace App\Models\Studio;

use Illuminate\Database\Eloquent\Model;

class DesignComment extends Model
{
    protected $table = 'studio_design_comments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime', 'revision' => 'integer'];
    }
}
