<?php

namespace App\Connectors\Telegram;

use App\Services\Media\UnsafeUrlException;
use App\Services\Media\UrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de la Bot API de Telegram (T-87) para UN bot. El token va en la RUTA (`/bot<token>/METODO`): por eso la URL no se registra nunca, ninguna excepcion
 * la lleva (ni como mensaje ni encadenada) y el token se marca `SensitiveParameter` para que tampoco salga en las trazas. La conexion se fija a la IP
 * validada (`UrlGuard`) y no se siguen redirecciones.
 */
final class TelegramClient
{
    /** La Bot API es siempre esta: no se configura (la URL lleva el token y solo hay un servidor legitimo). */
    private const API = 'https://api.telegram.org';

    /** Errores de cURL en los que la peticion NO llego a salir (DNS, conexion rechazada, TLS, proxy): reintentar es seguro. El resto (timeout, corte) es ambiguo. */
    private const NOT_SENT = [5, 6, 7, 35, 51, 58, 59, 60];

    public function __construct(#[\SensitiveParameter] private readonly string $token) {}

    /**
     * Llama a un metodo y devuelve su `result`.
     *
     * @param  array<string, scalar>  $params
     * @param  array{name: string, contents: string, filename: string}|null  $file  Fichero multipart (p. ej. la foto de `sendPhoto`).
     * @return array<string, mixed>
     *
     * @throws TelegramException
     */
    public function call(string $method, array $params = [], ?array $file = null): array
    {
        $guard = app(UrlGuard::class);

        try {
            $validated = $guard->validate(self::API.'/bot'.$this->token.'/'.$method);
        } catch (UnsafeUrlException) {
            throw new TelegramException('El servidor de Telegram configurado no es válido.');
        }

        $http = Http::acceptJson()->timeout((int) config('social.http_timeout', 15))->withoutRedirecting()->withOptions($guard->requestOptions($validated));

        try {
            $response = $file !== null
                ? $http->attach($file['name'], $file['contents'], $file['filename'])->post($validated->url, $params)
                : $http->asForm()->post($validated->url, $params);
        } catch (ConnectionException $e) {
            // El mensaje de la excepcion original lleva la URL (con el token): no se propaga ni se encadena. Solo se lee de el el codigo de cURL.
            $errno = preg_match('/cURL error (\d+)/', $e->getMessage(), $m) === 1 ? (int) $m[1] : null;

            throw new TelegramException('No se pudo conectar con Telegram.', ambiguous: ! in_array($errno, self::NOT_SENT, true));
        } catch (RequestException $e) {
            // Respuesta cortada a mitad o error del cliente HTTP: se trata como el error HTTP que traia, nunca como un fallo sin controlar.
            throw new TelegramException('Telegram respondió con un error.', $e->response->status());
        }

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if (($json['ok'] ?? false) === true && $response->successful()) {
            return is_array($json['result'] ?? null) ? $json['result'] : [];
        }

        $description = is_string($json['description'] ?? null) ? mb_substr(trim((string) preg_replace('/[\x00-\x1f\x7f\s]+/u', ' ', $json['description'])), 0, 200) : '';
        $retry = $json['parameters']['retry_after'] ?? null;

        throw new TelegramException(
            'Telegram respondió con un error.',
            (int) ($json['error_code'] ?? $response->status()),
            $description,
            is_int($retry) && $retry > 0 ? min($retry, 86400) : null,
        );
    }
}
