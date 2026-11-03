<?php

namespace App\Models\Studio;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class BrandKit extends Model
{
    use BelongsToUser;

    protected $table = 'studio_brand_kits';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['palette' => 'array', 'fonts' => 'array', 'logos' => 'array'];
    }
}
