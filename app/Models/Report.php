<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

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

    protected static function booted(): void
    {
        // El fichero vive en el disco de media: se borra con el informe (borrado manual, purga, marca o usuario).
        static::deleting(fn (Report $r) => $r->deleteFile());
    }

    /** Borra informes y ficheros de una marca o un usuario (los FK en cascada no disparan eventos de modelo). */
    public static function purgeFor(string $column, int $id): void
    {
        static::withoutUserScope()->where($column, $id)->get()->each->delete();
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY && $this->path !== null;
    }

    /** En curso y no atascado. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_PROCESSING])
            ->where('updated_at', '>=', now()->subHours((int) config('reports.stuck_hours', 2)));
    }

    /** `pending`/`processing` que lleva demasiado sin avanzar (worker caido, job perdido). */
    public function isStuck(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true)
            && $this->updated_at !== null
            && $this->updated_at->lt(now()->subHours((int) config('reports.stuck_hours', 2)));
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PROCESSING], true) && ! $this->isStuck();
    }

    /** Enlace de descarga firmado SIN sesion: valido unos minutos y atado al informe y a su dueno (`owner` va en la firma). */
    public function signedDownloadUrl(): string
    {
        return URL::temporarySignedRoute('reports.download', now()->addMinutes((int) config('reports.download_minutes', 5)), [
            'report' => $this->id, 'owner' => $this->user_id,
        ]);
    }

    public function deleteFile(): void
    {
        if ($this->path === null) {
            return;
        }

        try {
            Storage::disk(config('filesystems.media'))->delete($this->path);
        } catch (\Throwable $e) {
            Log::warning('report.file_delete_failed', ['report_id' => $this->id, 'error' => $e->getMessage()]);
        }
    }

    /** Nombre de descarga sin caracteres peligrosos. */
    public function downloadName(string $brandName): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $brandName), '-') ?: 'marca';

        return sprintf('informe-%s-%s_%s.%s', strtolower($slug), $this->from->format('Y-m-d'), $this->to->format('Y-m-d'), $this->format);
    }
}
