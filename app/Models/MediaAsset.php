<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Scopes\UserScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;

/**
 * Fichero de media subido por un usuario (directo navegador -> R2). Guarda metadatos para validarlo
 * por red/tipo (T-18) y la URL accesible por Meta/TikTok.
 */
class MediaAsset extends Model
{
    use BelongsToUser, HasFactory;

    public const STATUS_PENDING = 'pendiente';

    public const STATUS_READY = 'listo';

    public const STATUS_INVALID = 'invalido';

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_URL = 'url';

    public const SOURCE_DRIVE = 'drive';

    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    protected $fillable = [
        'disk', 'path', 'original_name', 'mime', 'kind', 'declared_size', 'size', 'width', 'height',
        'duration', 'fps', 'codec', 'audio_codec', 'status', 'upload_id', 'error', 'source', 'source_ref', 'import_state',
    ];

    protected $attributes = ['source' => self::SOURCE_UPLOAD];

    protected $hidden = ['upload_id', 'source_ref', 'import_state'];

    protected function casts(): array
    {
        return [
            'declared_size' => 'integer',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration' => 'float',
            'fps' => 'float',
            'import_state' => 'array',
        ];
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_media')->withoutGlobalScope(UserScope::class);
    }

    public function isVideo(): bool
    {
        return $this->kind === self::KIND_VIDEO;
    }

    /**
     * Lista y con fichero: en disco local se comprueba que exista (un `stat`), para no ofrecer una vista previa rota; en R2 se
     * da por presente (comprobarlo seria una peticion por asset en cada listado).
     */
    public function previewable(): bool
    {
        if (! $this->isReady() || $this->disk === null || $this->path === null) {
            return false;
        }

        $disk = Storage::disk($this->disk);

        return $disk instanceof AwsS3V3Adapter || $disk->exists($this->path);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /** URL temporal firmada que las redes pueden descargar (Meta, TikTok PULL_FROM_URL). */
    public function publicUrl(?int $minutes = null): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $this->path,
            now()->addMinutes($minutes ?? (int) config('media.public_url_ttl', 720)),
        );
    }

    /** Campos seguros para el navegador. */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'kind' => $this->kind,
            'mime' => $this->mime,
            'size' => $this->size ?? $this->declared_size,
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration,
            'fps' => $this->fps,
            'status' => $this->status,
            'error' => $this->error,
            'source' => $this->source,
            // Progreso de una importacion remota (barra de progreso). Nunca la URL, el validador ni el resto de `import_state`.
            'bytes' => $this->import_state['bytes'] ?? null,
            'total' => $this->import_state['total'] ?? null,
            // Para previsualizar la media ya guardada (post reabierto, creado por MCP, elegido de la biblioteca): solo para su dueño.
            'preview_url' => $this->previewable() ? route('media.file', $this, absolute: false) : null,
        ];
    }
}
