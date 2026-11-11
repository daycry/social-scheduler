<?php

namespace App\Models\Studio;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class StudioImageJob extends Model
{
    use BelongsToUser;

    protected $guarded = ['id'];

    protected $hidden = ['model_path'];
}
