<?php

namespace App\Models;

use App\Models\Concerns\PrunesByAge;
use Illuminate\Database\Eloquent\Model;

/** Entrada del registro de auditoria. Solo se escribe via `App\Services\AuditLog`. */
class AuditLog extends Model
{
    use PrunesByAge;

    protected static function retentionKey(): string
    {
        return 'audit_logs_days';
    }

    public const UPDATED_AT = null;

    protected $guarded = [];
}
