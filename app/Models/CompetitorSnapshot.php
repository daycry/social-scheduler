<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

/** Foto diaria de un competidor: seguidores y publicaciones (los unicos datos publicos de Business Discovery que seguimos). */
class CompetitorSnapshot extends Model
{
    use BelongsToUser;

    protected $fillable = ['user_id', 'competitor_id', 'date', 'followers_count', 'media_count'];

    protected function casts(): array
    {
        return ['followers_count' => 'integer', 'media_count' => 'integer'];
    }
}
