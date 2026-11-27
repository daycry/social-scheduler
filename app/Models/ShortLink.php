<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

/**
 * Enlace corto `/s/{code}` de un usuario (T-89). Los clics viven en `short_link_clicks`, agregados por dia UTC.
 * El `code` empieza por una letra mayuscula: asi nunca coincide con el slug en minusculas de un sitio publicado.
 */
class ShortLink extends Model
{
    use BelongsToUser;

    public const CODE_PATTERN = '[A-Z][A-Z0-9]{7}';

    protected $fillable = ['user_id', 'code', 'url', 'label', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function shortUrl(): string
    {
        return url('/s/'.$this->code);
    }
}
