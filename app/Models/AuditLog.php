<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Entrada del registro de auditoria. Solo se escribe via `App\Services\AuditLog`. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];
}
