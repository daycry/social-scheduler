<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

/** Historial de intentos de publicacion de un target (base de la tasa de exito, improvement-plan). */
class PublishAttempt extends Model
{
    use BelongsToUser;

    public const OUTCOME_STARTED = 'iniciado';

    public const OUTCOME_PUBLISHED = 'publicado';

    public const OUTCOME_PROCESSING = 'procesando';

    public const OUTCOME_TRANSIENT = 'transitorio';

    public const OUTCOME_PERMANENT = 'permanente';

    public const OUTCOME_QUOTA = 'cuota';

    protected $fillable = ['post_target_id', 'attempt_no', 'outcome', 'error_code', 'message', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'attempt_no' => 'integer'];
    }
}
