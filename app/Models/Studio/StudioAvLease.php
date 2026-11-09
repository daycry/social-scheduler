<?php

namespace App\Models\Studio;

use Illuminate\Database\Eloquent\Model;

/** Internal capability bound to a workspace owner, generation and one phase. Never serialized publicly. */
class StudioAvLease extends Model
{
    protected $table = 'studio_av_leases';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $hidden = ['workspace_id', 'generation', 'phase', 'item', 'category', 'reserved_bytes', 'actual_bytes', 'status', 'result', 'expires_at', 'started_at', 'queue_job_id', 'input_published', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['workspace_id' => 'integer', 'item' => 'integer', 'reserved_bytes' => 'integer', 'actual_bytes' => 'integer', 'queue_job_id' => 'integer', 'input_published' => 'boolean', 'result' => 'array', 'expires_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime'];
    }
}
