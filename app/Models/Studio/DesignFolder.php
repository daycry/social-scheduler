<?php

namespace App\Models\Studio;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class DesignFolder extends Model
{
    use BelongsToUser;

    protected $table = 'studio_design_folders';

    protected $guarded = ['id'];
}
