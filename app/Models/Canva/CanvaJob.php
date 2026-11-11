<?php

namespace App\Models\Canva;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Model;

/** Trabajo asincrono contra Canva (T-16): exportar un diseno a la biblioteca o enviar un recurso a Canva. */
class CanvaJob extends Model
{
    use BelongsToUser;

    public const KIND_EXPORT = 'export';

    public const KIND_UPLOAD = 'upload';

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected $hidden = ['download_urls'];

    protected function casts(): array
    {
        return ['options' => 'array', 'output_media_ids' => 'array', 'download_urls' => 'array', 'polls' => 'integer'];
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::PROCESSING], true);
    }
}
