<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    use BelongsToUser;

    public const STATES = ['pending' => 'Pendiente', 'delivered' => 'Entregada', 'blocked' => 'Bloqueada', 'failed' => 'Fallida', 'exhausted' => 'Reintentos agotados', 'cancelled' => 'Cancelada'];

    public const ERRORS = [
        'subscription_unavailable' => 'El receptor está pausado, eliminado o ya no recibe este evento.',
        'unsafe_url' => 'La URL ya no apunta a una dirección pública permitida.',
        'redirect' => 'El receptor devolvió una redirección. No se ha seguido.',
        'invalid_secret' => 'No se puede leer el secreto. Crea un receptor nuevo.',
        'connection_error' => 'No se pudo conectar con el receptor.',
        'http_error' => 'El receptor respondió con un error HTTP.',
        'worker_failed' => 'El trabajo terminó sin completar la entrega.',
    ];

    protected $attributes = ['status' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'last_http_status' => 'integer', 'next_attempt_at' => 'datetime', 'delivered_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
