<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Exportación completa de los datos de un usuario en un ZIP (T-84). El fichero vive en el disco privado `exports.disk` y solo se
 * entrega con un enlace firmado y de vida corta (`signedDownloadUrl`). `in_progress` es 1 mientras está en cola o en curso y NULL después:
 * su índice único con `user_id` limita a una exportación activa por usuario.
 */
class DataExport extends Model
{
    use BelongsToUser;

    protected $table = 'full_exports';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = ['user_id', 'status', 'in_progress', 'include_media', 'path', 'size', 'file_count', 'error', 'started_at', 'heartbeat_at', 'completed_at', 'expires_at'];

    protected $attributes = ['status' => self::STATUS_PENDING];

    protected function casts(): array
    {
        return ['in_progress' => 'boolean', 'include_media' => 'boolean', 'size' => 'integer', 'file_count' => 'integer', 'started_at' => 'datetime', 'heartbeat_at' => 'datetime', 'completed_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** Listo y todavía dentro de su plazo de conservación. */
    public function isDownloadable(): bool
    {
        return $this->status === self::STATUS_READY && $this->path !== null && $this->expires_at?->isFuture() === true;
    }

    /** Enlace firmado y con caducidad, atado al usuario (`owner` va en la firma y debe ser el de la sesión). */
    public function signedDownloadUrl(): string
    {
        return URL::temporarySignedRoute('profile.export.download', now()->addMinutes((int) config('exports.link_minutes')), ['export' => $this->id, 'owner' => $this->user_id]);
    }

    public static function disk(): Filesystem
    {
        return Storage::disk((string) config('exports.disk'));
    }

    /** Borra el fichero (si existe) y olvida su ruta. */
    public function deleteFile(): void
    {
        if ($this->path !== null) {
            self::disk()->delete($this->path);
            $this->path = null;
        }
    }

    /** Ruta definitiva del ZIP de una exportación: por usuario, para poder borrar el directorio entero con la cuenta. */
    public static function pathFor(int $userId, int $id): string
    {
        return 'full-exports/'.$userId.'/'.$id.'.zip';
    }

    /** Fichero temporal donde se arma el ZIP: ruta conocida y determinista por exportación, para poder borrarlo desde `failed()` y `exports:prune`. */
    public function temporaryPath(): string
    {
        return rtrim((string) config('exports.tmp_dir'), '/\\').DIRECTORY_SEPARATOR.$this->user_id.'-'.$this->id.'.zip.part';
    }

    /** Una exportación que ya no está en curso libera el cupo del usuario. */
    public function release(string $status, ?string $error = null): void
    {
        $this->forceFill(['status' => $status, 'in_progress' => null, 'error' => $error])->save();
    }
}
