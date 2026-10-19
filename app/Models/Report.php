<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Informe generado de una marca y rango de fechas (PDF o CSV), aislado por usuario. */
class Report extends Model
{
    use BelongsToUser, HasFactory;

    public const FORMATS = ['pdf', 'csv'];

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['user_id', 'brand_id', 'format', 'from', 'to', 'compare', 'status', 'path', 'size', 'error', 'generated_at'];

    protected function casts(): array
    {
        return ['from' => 'date:Y-m-d', 'to' => 'date:Y-m-d', 'generated_at' => 'datetime', 'size' => 'integer'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY && $this->path !== null;
    }

    /** Nombre de descarga sin caracteres peligrosos. */
    public function downloadName(string $brandName): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $brandName), '-') ?: 'marca';

        return sprintf('informe-%s-%s_%s.%s', strtolower($slug), $this->from->format('Y-m-d'), $this->to->format('Y-m-d'), $this->format);
    }
}
