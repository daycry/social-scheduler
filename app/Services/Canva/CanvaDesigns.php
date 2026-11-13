<?php

namespace App\Services\Canva;

use App\Models\Brand;
use App\Models\Canva\CanvaConnection;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Disenos de Canva del usuario (T-16): listar y buscar, crear con el tamaño de un formato social y enlaces explicitos «Abrir en Canva».
 * Cada enlace de edicion lleva un `correlation_state` FIRMADO (HMAC con la clave de la app) y ligado al usuario, la marca y el diseño; al volver
 * de Canva, `resolveReturn()` lo valida y devuelve a quien ha vuelto al diseño correcto. Canva limita ese valor a 50 caracteres.
 */
class CanvaDesigns
{
    /** Formatos sociales para «Crear en Canva» (mismas medidas que los formatos del estudio). */
    public const FORMATS = [
        'square' => ['label' => 'Publicación cuadrada', 'width' => 1080, 'height' => 1080],
        'portrait' => ['label' => 'Instagram vertical', 'width' => 1080, 'height' => 1350],
        'story' => ['label' => 'Historia / Reel', 'width' => 1080, 'height' => 1920],
        'landscape' => ['label' => 'Presentación / vídeo', 'width' => 1920, 'height' => 1080],
        'facebook' => ['label' => 'Publicación de Facebook', 'width' => 1200, 'height' => 630],
        'pinterest' => ['label' => 'Pin de Pinterest', 'width' => 1000, 'height' => 1500],
    ];

    private const STATE_MAX = 50;

    private const STATE_TTL_DAYS = 3;

    public function __construct(private readonly CanvaClient $client, private readonly CanvaConnections $connections, private readonly CanvaJwks $jwks) {}

    /** @return array{items: list<array<string, mixed>>, continuation: ?string} */
    public function list(User $user, Brand $brand, CanvaConnection $connection, ?string $query, ?string $continuation): array
    {
        $query = $query !== null ? trim(mb_substr($query, 0, 255)) : null;
        $continuation = $continuation !== null ? mb_substr($continuation, 0, 500) : null;
        $page = $this->client->designs($this->connections->accessToken($connection), $query ?: null, $continuation);

        return ['items' => array_values(array_filter(array_map(fn (array $d) => $this->present($user, $brand, $d), $page['items']))), 'continuation' => $page['continuation']];
    }

    /** @return array<string, mixed> */
    public function create(User $user, Brand $brand, CanvaConnection $connection, string $format, ?string $title): array
    {
        $size = self::FORMATS[$format] ?? null;
        if ($size === null) {
            throw new CanvaException('Elige un formato de la lista.', 422, 'bad_format');
        }
        if (! CanvaCapabilities::allows('create', [], (array) $connection->scopes)) {
            throw new CanvaException((string) CanvaCapabilities::reason('create', [], (array) $connection->scopes), 403, 'scope_missing');
        }
        $title = Str::limit(trim((string) $title) !== '' ? trim((string) $title) : $brand->name.' · '.$size['label'], 255, '');
        $design = $this->client->createDesign($this->connections->accessToken($connection), $size['width'], $size['height'], $title);
        $presented = $this->present($user, $brand, $design);
        if ($presented === null) {
            throw new CanvaException('Canva no devolvió el diseño creado. Inténtalo de nuevo.', 502, 'bad_response');
        }

        return $presented;
    }

    /** Un diseño por id (enlace de edicion fresco: los de Canva son temporales). @return array<string, mixed> */
    public function get(User $user, Brand $brand, CanvaConnection $connection, string $designId): array
    {
        $design = $this->client->design($this->connections->accessToken($connection), self::validDesignId($designId));
        $presented = $this->present($user, $brand, $design);
        if ($presented === null) {
            throw new CanvaException('Canva no encuentra ese diseño.', 404, 'not_found');
        }

        return $presented;
    }

    /**
     * Presenta un diseño de la API de Canva con solo lo que la interfaz necesita. Los enlaces solo se aceptan si son https de canva.com.
     *
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>|null
     */
    public function present(User $user, Brand $brand, array $design): ?array
    {
        $id = $design['id'] ?? null;
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{4,40}$/', $id)) {
            return null;
        }
        $thumb = $design['thumbnail']['url'] ?? null;
        $edit = self::canvaUrl($design['urls']['edit_url'] ?? null);

        return [
            'id' => $id,
            'title' => Str::limit((string) ($design['title'] ?? 'Sin título'), 255, ''),
            'thumbnail' => is_string($thumb) && str_starts_with($thumb, 'https://') ? $thumb : null,
            'width' => isset($design['thumbnail']['width']) ? (int) $design['thumbnail']['width'] : null,
            'height' => isset($design['thumbnail']['height']) ? (int) $design['thumbnail']['height'] : null,
            'updated_at' => isset($design['updated_at']) ? (int) $design['updated_at'] : null,
            // Enlace externo EXPLICITO: la interfaz lo abre en una pestaña nueva con rel=noopener. Lleva el correlation_state de retorno.
            'open_url' => $edit !== null ? $edit.(str_contains($edit, '?') ? '&' : '?').'correlation_state='.rawurlencode($this->sign($user, $brand, $id)) : null,
            'view_url' => self::canvaUrl($design['urls']['view_url'] ?? null),
        ];
    }

    /** Enlace permanente al diseño (para el «Origen: Canva» de un recurso importado). */
    public static function designLink(string $designId): string
    {
        return 'https://www.canva.com/design/'.rawurlencode($designId).'/edit';
    }

    public static function validDesignId(string $designId): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{4,40}$/', $designId)) {
            throw new CanvaException('El identificador del diseño no es válido.', 422, 'bad_design');
        }

        return $designId;
    }

    /** `correlation_state` firmado: «marca.diseño.hora.firma» (≤ 50 caracteres), ligado al usuario. */
    public function sign(User $user, Brand $brand, string $designId): string
    {
        $prefix = $brand->id.'.'.$designId.'.'.base_convert((string) now()->timestamp, 10, 36).'.';
        $length = min(22, self::STATE_MAX - strlen($prefix));
        if ($length < 12) {
            throw new CanvaException('No se pudo preparar el retorno desde Canva para este diseño.', 422, 'state_too_long');
        }

        return $prefix.substr($this->mac($user->id, $prefix), 0, $length);
    }

    /**
     * Valida la vuelta desde Canva: verifica la FIRMA del `correlation_jwt` con las claves publicas de Canva (JWKS, RS256; `CanvaJwks` comprueba
     * tambien audiencia y caducidad), exige el tipo `rti` y el `correlation_state` FIRMADO por esta app para ESTE usuario. Devuelve la marca
     * (del usuario) y el diseño, o null si algo no cuadra (incluida una firma que no se puede verificar).
     *
     * @return array{brand: Brand, design_id: string}|null
     */
    public function resolveReturn(User $user, string $jwt): ?array
    {
        $claims = $this->jwks->verify($jwt);
        if ($claims === null || ($claims['type'] ?? null) !== 'rti' || ! is_string($claims['correlation_state'] ?? null)) {
            return null;
        }

        $state = $claims['correlation_state'];
        if (strlen($state) > self::STATE_MAX || ! preg_match('/^(\d{1,12})\.([A-Za-z0-9_-]{4,40})\.([0-9a-z]{4,8})\.([A-Za-z0-9_-]{12,22})$/', $state, $m)) {
            return null;
        }
        [, $brandId, $designId, $stamp, $sig] = $m;
        $issued = (int) base_convert($stamp, 36, 10);
        if ($issued > now()->timestamp + 300 || $issued < now()->subDays(self::STATE_TTL_DAYS)->timestamp) {
            return null;
        }
        $prefix = $brandId.'.'.$designId.'.'.$stamp.'.';
        if (! hash_equals(substr($this->mac($user->id, $prefix), 0, strlen($sig)), $sig)) {
            return null;
        }
        if (isset($claims['design_id']) && $claims['design_id'] !== $designId) {
            return null;
        }
        $brand = Brand::withoutUserScope()->where('user_id', $user->id)->find((int) $brandId);

        return $brand === null ? null : ['brand' => $brand, 'design_id' => $designId];
    }

    private function mac(int $userId, string $prefix): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'canva-return|'.$userId.'|'.$prefix, (string) config('app.key'), true)), '+/', '-_'), '=');
    }

    public static function canvaUrl(mixed $url): ?string
    {
        if (! is_string($url) || strlen($url) > 2000) {
            return null;
        }
        $p = parse_url($url);

        return ($p['scheme'] ?? '') === 'https' && preg_match('/(^|\.)canva\.com$/i', (string) ($p['host'] ?? '')) && ! isset($p['user'], $p['pass']) ? $url : null;
    }
}
