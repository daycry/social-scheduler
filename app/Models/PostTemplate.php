<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Plantilla de la biblioteca (PA-06): texto + media + destinos reutilizables. `targets` = [{account_id, content_type}].
 */
class PostTemplate extends Model
{
    use BelongsToUser;

    protected $fillable = ['name', 'text', 'targets'];

    protected function casts(): array
    {
        return ['targets' => 'array'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }

    public function media(): BelongsToMany
    {
        return $this->belongsToMany(MediaAsset::class, 'post_template_media')
            ->withoutGlobalScope(UserScope::class)->withPivot('position')->orderByPivot('position');
    }
}
