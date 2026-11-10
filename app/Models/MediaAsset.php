<?php

namespace App\Models;

use App\Enums\PostStatus;
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

    public const SOURCE_GIPHY = 'giphy';

    public const SOURCE_CANVA = 'canva';

    public const KIND_IMAGE = 'image';

    public const KIND_VIDEO = 'video';

    public const KIND_AUDIO = 'audio';

    protected $fillable = [
        'disk', 'path', 'original_name', 'mime', 'kind', 'declared_size', 'size', 'width', 'height',
        'duration', 'fps', 'codec', 'audio_codec', 'status', 'upload_id', 'error', 'source', 'source_ref', 'import_state',
    ];

    protected $attributes = ['source' => self::SOURCE_UPLOAD];

    protected $hidden = ['upload_id', 'source_ref', 'import_state', 'origin'];

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
            'origin' => 'array',
        ];
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_media')->withoutGlobalScope(UserScope::class);
    }

    /**
     * Media que un destino sin publicar usa en su lista propia (`post_targets.media`, ids y portadas): no esta en `post_media`, asi que
     * `posts()` no la ve (T-17). Comparacion en PHP para no depender del dialecto JSON de MySQL o SQLite.
     */
    public function usedByTargetMedia(): bool
    {
        return PostTarget::withoutUserScope()->where('user_id', $this->user_id)->whereNotNull('media')
            ->where('status', '!=', PostStatus::Published->value)->get(['id', 'media'])
            ->contains(fn (PostTarget $t) => collect($t->media)->contains(fn ($m) => ($m['id'] ?? null) === $this->id || ($m['cover_id'] ?? null) === $this->id));
    }

    /** ¿La usa alguna publicacion, por la media comun o por la propia de un destino? */
    public function usedByPosts(): bool
    {
        return $this->posts()->exists() || $this->usedByTargetMedia();
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
            // Origen explicito de un recurso importado de Canva (T-16): id del diseño y enlace, nada mas.
            'origin' => $this->source === self::SOURCE_CANVA ? $this->publicOrigin() : null,
            // Progreso de una importacion remota (barra de progreso). Nunca la URL, el validador ni el resto de `import_state`.
            'bytes' => $this->import_state['bytes'] ?? null,
            'total' => $this->import_state['total'] ?? null,
            // Latido: pasadas que el worker ha EMPEZADO; la UI lo usa (junto a `bytes`) para distinguir «cola parada» de una pasada larga.
            'runs' => $this->import_state['runs'] ?? null,
            // Para previsualizar la media ya guardada (post reabierto, creado por MCP, elegido de la biblioteca): solo para su dueño.
            'preview_url' => $this->previewable() ? route('media.file', $this, absolute: false) : null,
        ];
    }

    /** @return array{provider: string, label: string, design_id: ?string, url: ?string, title: ?string}|null */
    public function publicOrigin(): ?array
    {
        $origin = (array) $this->origin;
        if (($origin['provider'] ?? null) !== 'canva') {
            return null;
        }

        return [
            'provider' => 'canva', 'label' => 'Canva',
            'design_id' => is_string($origin['design_id'] ?? null) ? $origin['design_id'] : null,
            'url' => is_string($origin['url'] ?? null) ? $origin['url'] : null,
            'title' => is_string($origin['title'] ?? null) ? $origin['title'] : null,
        ];
    }
}
