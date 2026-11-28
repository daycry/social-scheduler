<?php

namespace App\Models\Canva;

use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\PrunesByAge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Trabajo asincrono contra Canva (T-16): exportar un diseno a la biblioteca, enviar un recurso a Canva o rellenar una plantilla de marca (Autofill). */
class CanvaJob extends Model
{
    use BelongsToUser;
    use PrunesByAge;

    protected static function retentionKey(): string
    {
        return 'canva_jobs_days';
    }

    protected static function pruneScope(Builder $query): Builder
    {
        return $query->whereIn('status', [self::READY, self::FAILED]);
    }

    public const KIND_EXPORT = 'export';

    public const KIND_UPLOAD = 'upload';

    public const KIND_AUTOFILL = 'autofill';

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['options' => 'array', 'output_media_ids' => 'array', 'polls' => 'integer'];
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::PROCESSING], true);
    }
}
