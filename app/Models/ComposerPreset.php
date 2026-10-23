<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Interruptores recordados por (marca, red) del compositor (C-04). Con ambito de usuario: una marca ajena no se ve. */
class ComposerPreset extends Model
{
    use BelongsToUser;

    protected $fillable = ['network', 'options'];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class)->withoutGlobalScope(UserScope::class);
    }
}
