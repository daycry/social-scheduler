<?php

namespace App\Services\Media;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Proxy del servidor hacia la API de Giphy (C-04b, D4). El navegador NUNCA ve la clave (`services.giphy.api_key`): pide aqui y recibe
 * solo lo que el selector necesita (id, titulo, miniatura y la URL del MP4), ya filtrado a los hosts de Giphy permitidos.
 *
 * Limites: la clave beta da 100 llamadas por hora, asi que las respuestas se cachean unos minutos y las llamadas REALES se cuentan en una
 * ventana por hora (`social.giphy.hourly_limit`); una respuesta cacheada no gasta cupo. El throttle por usuario va en la ruta.
 */
class GiphyClient
{
    public const BASE = 'https://api.giphy.com/v1/gifs';

    public const RATE_LIMITED = 'Has hecho muchas búsquedas de GIF; prueba en unos minutos.';

    public const INVALID_KEY = 'La clave de Giphy no es válida o no tiene permiso. Avisa al administrador.';

    public const UNAVAILABLE = 'Giphy no responde ahora mismo. Inténtalo de nuevo en unos minutos.';

    /** Sin clave no hay buscador: el boton no aparece. */
    public static function configured(): bool
    {
        return filled(config('services.giphy.api_key'));
    }

    /**
     * ¿La URL es un MP4 alojado en un host permitido de Giphy (https, 443, sin credenciales)? Es la lista cerrada de la que descarga el
     * servidor: cualquier otra URL se rechaza antes de pedir nada.
     */
    public static function allowedMediaUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strlen($url) <= 2048
            && ! preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && ! isset($parts['user']) && ! isset($parts['pass'])
            && (! isset($parts['port']) || (int) $parts['port'] === 443)
            && self::allowedHost((string) ($parts['host'] ?? ''))
            && str_ends_with(strtolower((string) ($parts['path'] ?? '')), '.mp4');
    }

    public static function allowedHost(string $host): bool
    {
        return (bool) preg_match((string) config('social.giphy.media_host_pattern'), strtolower($host));
    }

    /** @return array{results: list<array{id: string, title: string, preview: string, width: int, height: int, mp4: string}>, next_offset: ?int} */
    public function search(string $query, int $offset = 0, ?int $userId = null): array
    {
        // Normalizada (minusculas, sin espacios de mas): «Gato», «gato» y « gato  » comparten cache y no gastan otra llamada.
        $q = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $query)));

        return $this->fetch('search', ['q' => $q], $offset, $userId);
    }

    /** @return array{results: list<array{id: string, title: string, preview: string, width: int, height: int, mp4: string}>, next_offset: ?int} */
    public function trending(int $offset = 0, ?int $userId = null): array
    {
        return $this->fetch('trending', [], $offset, $userId);
    }

    /**
     * URL del MP4 de un GIF pedido a Giphy POR SU ID (el servidor no se fia de la URL del cliente). El endpoint por id no filtra por
     * rating: se comprueba el campo `rating` de la respuesta contra `social.giphy.rating`. Gasta cupo como cualquier llamada real.
     *
     * @throws GiphyException si Giphy no lo conoce, supera el rating permitido o no hay cupo.
     */
    public function mp4ById(string $id, ?int $userId = null): string
    {
        if (! self::configured()) {
            throw new GiphyException('El buscador de GIFs no está configurado.', 404);
        }
        if (! preg_match('/^[A-Za-z0-9]{3,40}$/', $id)) {
            throw new GiphyException('Ese GIF no es válido.', 422);
        }

        $key = 'giphy:'.sha1(json_encode(['id', $id], JSON_THROW_ON_ERROR));
        $ttl = (int) config('social.giphy.cache_minutes', 10);
        $item = $ttl > 0 ? Cache::get($key) : null;

        if (! is_array($item)) {
            $item = $this->call('/'.$id, [], $userId)->json('data');
            if (! is_array($item)) {
                throw new GiphyException('Ese GIF no existe en Giphy.', 422);
            }
            if ($ttl > 0) {
                Cache::put($key, $item, now()->addMinutes($ttl));
            }
        }

        $order = ['y' => 0, 'g' => 1, 'pg' => 2, 'pg-13' => 3, 'r' => 4];
        $max = $order[strtolower((string) config('social.giphy.rating', 'pg-13'))] ?? 3;
        $rating = $order[strtolower((string) data_get($item, 'rating', 'r'))] ?? 4;
        if ($rating > $max) {
            throw new GiphyException('Ese GIF no está permitido por su clasificación.', 422);
        }

        $mapped = $this->map($item);

        return is_array($mapped) && $mapped['id'] === $id ? $mapped['mp4'] : throw new GiphyException('Ese GIF no tiene un MP4 que se pueda importar.', 422);
    }

    /**
     * @param  array<string, string>  $params
     * @return array{results: list<array<string, mixed>>, next_offset: ?int}
     *
     * @throws GiphyException
     */
    private function fetch(string $endpoint, array $params, int $offset, ?int $userId = null): array
    {
        if (! self::configured()) {
            throw new GiphyException('El buscador de GIFs no está configurado.', 404);
        }

        $limit = max(1, min(50, (int) config('social.giphy.limit', 24)));
        $offset = max(0, min(4999, $offset));
        $rating = (string) config('social.giphy.rating', 'pg-13');
        $key = 'giphy:'.sha1(json_encode([$endpoint, $params, $offset, $limit, $rating], JSON_THROW_ON_ERROR));
        $ttl = (int) config('social.giphy.cache_minutes', 10);

        if ($ttl > 0 && is_array($hit = Cache::get($key))) {
            return $hit;
        }

        $response = $this->call('/'.$endpoint, $params + ['limit' => $limit, 'offset' => $offset, 'rating' => $rating, 'lang' => 'es'], $userId);

        if (! is_array($response->json('data'))) {
            throw new GiphyException(self::UNAVAILABLE);
        }

        $results = [];
        foreach ($response->json('data') as $item) {
            if (is_array($mapped = $this->map($item))) {
                $results[] = $mapped;
            }
        }

        $total = (int) $response->json('pagination.total_count', 0);
        $next = $offset + $limit;
        $payload = ['results' => $results, 'next_offset' => $next < $total && $next <= 4999 ? $next : null];

        if ($ttl > 0) {
            Cache::put($key, $payload, now()->addMinutes($ttl));
        }

        return $payload;
    }

    /**
     * Llamada REAL a Giphy: gasta cupo (global y del usuario), traduce los fallos a mensajes sin la clave y devuelve la respuesta buena.
     *
     * @param  array<string, mixed>  $params
     *
     * @throws GiphyException
     */
    private function call(string $path, array $params, ?int $userId): Response
    {
        $spent = $this->spend($userId);

        try {
            $response = Http::timeout(8)->acceptJson()->get(self::BASE.$path, $params + ['api_key' => (string) config('services.giphy.api_key')]);
        } catch (ConnectionException) {
            // El mensaje de la excepcion lleva la URL con la clave: no se propaga ni se registra.
            throw new GiphyException(self::UNAVAILABLE);
        }

        if ($response->status() === 429) {
            throw new GiphyException(self::RATE_LIMITED, 429);
        }

        if (in_array($response->status(), [401, 403], true)) {
            // Clave invalida o revocada: no es culpa del usuario ni gasta su cupo. Se registra SIN la clave ni la URL.
            $this->refund($spent);
            Log::warning('giphy.invalid_key', ['status' => $response->status()]);
            throw new GiphyException(self::INVALID_KEY);
        }

        if (! $response->successful()) {
            throw new GiphyException(self::UNAVAILABLE);
        }

        return $response;
    }

    /**
     * Cuenta una llamada real en la ventana de la hora actual: tope global Y tope por usuario (uno solo no agota el cupo de todos).
     *
     * @return list<string> claves incrementadas (para devolver el cupo si la clave es invalida)
     */
    private function spend(?int $userId): array
    {
        $hour = now('UTC')->format('YmdH');
        $limits = ['giphy:calls:'.$hour => (int) config('social.giphy.hourly_limit', 90)];
        if ($userId !== null) {
            $limits = ['giphy:calls:u'.$userId.':'.$hour => (int) config('social.giphy.per_user_hourly_limit', 30)] + $limits;
        }

        $spent = [];
        foreach ($limits as $key => $limit) {
            Cache::add($key, 0, now()->addHours(2));
            $spent[] = $key;
            if ((int) Cache::increment($key) > $limit) {
                $this->refund($spent);
                throw new GiphyException(self::RATE_LIMITED, 429);
            }
        }

        return $spent;
    }

    /** @param list<string> $keys */
    private function refund(array $keys): void
    {
        foreach ($keys as $key) {
            Cache::decrement($key);
        }
    }

    /** @return array{id: string, title: string, preview: string, width: int, height: int, mp4: string}|null */
    private function map(mixed $item): ?array
    {
        try {
            $id = (string) data_get($item, 'id', '');
            $preview = data_get($item, 'images.fixed_width_small.url') ?? data_get($item, 'images.fixed_width.url');
            $mp4 = data_get($item, 'images.original_mp4.mp4') ?? data_get($item, 'images.fixed_height.mp4');
        } catch (Throwable) {
            return null;
        }

        // Solo se ofrece lo que el servidor podria importar: id sencillo y URLs de los hosts de Giphy (la miniatura la pinta el navegador).
        if (! preg_match('/^[A-Za-z0-9]{3,40}$/', $id) || ! is_string($preview) || ! is_string($mp4)
            || ! str_starts_with($preview, 'https://') || ! self::allowedHost((string) parse_url($preview, PHP_URL_HOST)) || ! self::allowedMediaUrl($mp4)) {
            return null;
        }

        return [
            'id' => $id,
            'title' => mb_substr(trim(strip_tags((string) data_get($item, 'title', ''))), 0, 120),
            'preview' => $preview,
            'width' => (int) data_get($item, 'images.fixed_width_small.width', 100),
            'height' => (int) data_get($item, 'images.fixed_width_small.height', 100),
            'mp4' => $mp4,
        ];
    }
}
