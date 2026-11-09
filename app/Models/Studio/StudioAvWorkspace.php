<?php

namespace App\Models\Studio;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

/** Internal allocation journal; it survives deletion of the export, brand or user. */
class StudioAvWorkspace extends Model
{
    use BelongsToUser;

    protected $table = 'studio_av_workspaces';

    protected $guarded = ['id'];

    protected $hidden = ['user_id', 'brand_id', 'export_id', 'uuid', 'fingerprint', 'plan', 'source_metadata', 'media_id', 'media_reserved_bytes', 'media_receipt', 'media_retiring', 'media_cleanup_after', 'active_until', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'brand_id' => 'integer', 'export_id' => 'integer', 'media_id' => 'integer', 'media_reserved_bytes' => 'integer', 'media_receipt' => 'array', 'media_retiring' => 'boolean', 'media_cleanup_after' => 'immutable_datetime', 'active_until' => 'immutable_datetime', 'plan' => 'array', 'source_metadata' => 'array'];
    }
}
