<?php

namespace App\Connectors\Meta;

use App\Connectors\Exceptions\ConnectorException;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente de Graph API para PUBLICAR (version fija `social.meta.graph_version`). Traduce los errores de Meta
 * a las excepciones de la cola: credenciales -> reconectar; limites -> transitorio/cuota; contenido o permisos
 * -> permanente (con mensaje legible); 5xx/red -> transitorio. Nunca registra tokens ni URLs firmadas.
 */
class GraphClient
{
    /** Subcodigos de error de la API de publicacion de Instagram -> [clase, codigo interno, mensaje]. */
    private const IG_SUBCODES = [
        2207042 => ['quota', 'cuota', 'Instagram ha alcanzado el limite diario de publicaciones de la cuenta.'],
        2207026 => ['permanent', 'video_no_admitido', 'Instagram no admite el formato del video (usa MP4/MOV con H.264 y audio AAC).'],
        2207023 => ['permanent', 'tipo_no_admitido', 'Instagram no admite este tipo de contenido para la cuenta.'],
        2207010 => ['permanent', 'caption_larga', 'El texto supera el limite de Instagram.'],
        2207028 => ['permanent', 'carrusel_invalido', 'Algun elemento del carrusel no cumple los requisitos de Instagram.'],
        2207052 => ['permanent', 'media_no_descargable', 'Instagram no pudo descargar el archivo desde la URL de la media.'],
        2207004 => ['permanent', 'imagen_grande', 'La imagen es demasiado grande para Instagram.'],
        2207005 => ['permanent', 'formato_no_admitido', 'Instagram no admite el formato del archivo.'],
        2207009 => ['permanent', 'proporcion', 'La proporcion de la imagen no es admitida por Instagram.'],
        2207020 => ['permanent', 'contenedor_caducado', 'El contenedor de media de Instagram caduco antes de publicarse.'],
        2207001 => ['transient', 'instagram_error', 'Instagram tuvo un error interno al crear la media.'],
        2207003 => ['transient', 'descarga_lenta', 'Instagram tardo demasiado en descargar el archivo.'],
        2207027 => ['transient', 'media_no_lista', 'La media de Instagram aun no esta lista para publicarse.'],
        2207032 => ['transient', 'creacion_fallida', 'Instagram no pudo crear la media; se reintentara.'],
    ];

    public function __construct(private readonly ?string $version = null) {}

    /**
     * El token viaja siempre en la cabecera `Authorization: Bearer` (nunca en la query, que acaba en logs de proxy).
     *
     * `$read` marca una LECTURA de insights (no una publicacion): un rechazo es un dato "no disponible" esperable, no un
     * fallo de publicacion, y se registra en `debug` (`meta.read_error`) en vez de en `warning` (`meta.publish_error`).
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query, string $token, bool $read = false): array
    {
        return $this->send(fn (PendingRequest $http) => $http->withToken($token)->get($this->url($path), $query), $read);
    }

    /** @return array<string, mixed> */
    public function post(string $path, array $params, string $token): array
    {
        return $this->send(fn (PendingRequest $http) => $http->withToken($token)->asForm()->post($this->url($path), $params));
    }

    /**
     * POST a una URL de subida de Meta (reels): solo hosts de Meta, con las cabeceras indicadas.
     *
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function postToUrl(string $url, array $headers): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! str_ends_with($host, '.facebook.com') || parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new PermanentPublishException('Meta devolvio una URL de subida no valida.', 'url_subida_invalida');
        }

        return $this->send(fn (PendingRequest $http) => $http->withHeaders($headers)->post($url));
    }

    /** @param \Closure(PendingRequest): Response $call */
    private function send(\Closure $call, bool $read = false): array
    {
        try {
            $response = $call(Http::timeout((int) config('social.http_timeout', 15))->acceptJson());
        } catch (ConnectionException) {
            // Puede que Meta haya recibido la peticion: el reintento consulta antes de publicar.
            throw new TransientPublishException('No se pudo contactar con Meta (tiempo agotado o red).', 'red');
        }

        return $this->parse($response, $read);
    }

    /** @return array<string, mixed> */
    private function parse(Response $response, bool $read = false): array
    {
        $json = $response->json() ?? [];

        if ($response->successful() && ! isset($json['error'])) {
            return $json;
        }

        $error = $json['error'] ?? [];
        $code = (int) ($error['code'] ?? 0);
        $sub = (int) ($error['error_subcode'] ?? 0);
        $message = (string) ($error['error_user_msg'] ?? $error['message'] ?? 'respuesta inesperada');

        if ($read) {
            Log::debug('meta.read_error', ['status' => $response->status(), 'code' => $code, 'subcode' => $sub]);
        } else {
            Log::warning('meta.publish_error', ['status' => $response->status(), 'code' => $code, 'subcode' => $sub]);
        }

        throw $this->exceptionFor($response, $code, $sub, $message);
    }

    private function exceptionFor(Response $response, int $code, int $sub, string $message): ConnectorException
    {
        if (GraphErrors::needsReconnect($code)) {
            return new ReconnectRequiredException('Meta rechazo las credenciales ('.$message.'). Reconecta la cuenta.');
        }

        if (isset(self::IG_SUBCODES[$sub])) {
            [$kind, $internal, $text] = self::IG_SUBCODES[$sub];

            return match ($kind) {
                'quota' => new QuotaExceededException($text, null, $internal),
                'transient' => new TransientPublishException($text, $internal),
                default => new PermanentPublishException($text, $internal),
            };
        }

        if (GraphErrors::isRateLimit($code)) {
            return new TransientPublishException('Meta limito temporalmente las peticiones ('.$message.').', 'rate_limit');
        }

        if ($code === 9007) {
            return new TransientPublishException('La media aun no esta lista para publicarse en Instagram.', 'media_no_lista');
        }

        if (in_array($code, [3, 10, 200, 299], true) || str_starts_with($message, '(#10)') || str_starts_with($message, '(#200)')) {
            return new PermanentPublishException('Faltan permisos en Meta para publicar ('.$message.'). Revisa los permisos concedidos y vuelve a conectar la cuenta.', 'permisos');
        }

        if ($response->serverError() || in_array($code, [1, 2], true)) {
            return new TransientPublishException('Meta no esta disponible temporalmente ('.$message.').', 'meta_no_disponible');
        }

        return new PermanentPublishException('Meta rechazo la publicacion: '.$message, 'rechazada');
    }

    private function url(string $path): string
    {
        return 'https://graph.facebook.com/'.($this->version ?? config('social.meta.graph_version', 'v23.0')).'/'.ltrim($path, '/');
    }
}
