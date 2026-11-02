<?php

namespace App\Connectors\Bluesky;

use App\Services\Media\UnsafeUrlException;
use App\Services\Media\UrlGuard;
use App\Services\Media\ValidatedUrl;

/**
 * Guardia SSRF (CWE-918) de las peticiones de Bluesky. Reutiliza `UrlGuard` (DNS validado, IP fijada con `CURLOPT_RESOLVE`, sin
 * privadas, loopback, link-local ni metadatos) y añade las reglas de una API: solo https y puerto 443 explicito o implicito, sin
 * credenciales ni redirecciones. Hay dos clases de host:
 *  - los del usuario (el PDS guardado en `meta.pds` y el host de un `did:web`): se validan en CADA peticion, no solo al conectar,
 *    porque el DNS puede cambiar (rebinding);
 *  - los fijos (entryway, directorio PLC, AppView, servicio de video): ademas deben estar en `social.bluesky.allowed_hosts`.
 * Los mensajes son fijos: nunca reflejan el host ni la respuesta.
 */
final class BlueskyHosts
{
    public function __construct(private readonly UrlGuard $guard) {}

    /**
     * Valida la URL de una peticion y devuelve la reconstruida (la que hay que pedir) con la IP a fijar.
     *
     * @throws BlueskyException
     */
    public function validate(string $url): ValidatedUrl
    {
        $parts = parse_url($url);

        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || (isset($parts['port']) && $parts['port'] !== 443)
            || isset($parts['user']) || isset($parts['pass']) || ! isset($parts['host'])) {
            throw self::invalid();
        }

        try {
            return $this->guard->validate($url);
        } catch (UnsafeUrlException) {
            throw self::invalid();
        }
    }

    /** URL de un host fijo: ademas de ser publica (se valida al pedirla), su host esta en la lista de permitidos. */
    public function fixed(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $allowed = array_map('strtolower', (array) config('social.bluesky.allowed_hosts', []));

        if ($host === '' || ! in_array($host, $allowed, true)) {
            throw new BlueskyException('El servidor de Bluesky configurado no esta permitido.', 'HostNotAllowed', 400);
        }

        return $url;
    }

    /** URL del documento de un `did:web:` (solo `did:web:<dominio>`: sin puerto, sin ruta ni escapes). */
    public static function didWebUrl(string $did): string
    {
        $id = substr($did, strlen('did:web:'));

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/i', $id)) {
            throw new BlueskyException('Ese identificador de Bluesky no es valido.', 'InvalidDid', 400);
        }

        return 'https://'.strtolower($id).'/.well-known/did.json';
    }

    private static function invalid(): BlueskyException
    {
        return new BlueskyException('El servidor de esa cuenta de Bluesky no es valido.', 'PdsInvalid', 400);
    }
}
