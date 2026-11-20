<?php

namespace App\Connectors\Google;

use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\PublishException;
use App\Connectors\Exceptions\QuotaExceededException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Network;
use App\Connectors\Support\UploadPassBudget;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP de la Data API v3 de YouTube: peticiones con el token de la cuenta, traduccion de los errores de la API a
 * excepciones del dominio y cupo diario (`YouTubeQuota`). Tambien lleva el reloj de la pasada de subida en curso
 * (`UploadPassBudget`), que acota el timeout de cada llamada para que una pasada nunca supere el `$timeout` del job; fuera de
 * una pasada no recorta nada.
 */
class YouTubeClient
{
    public const API = 'https://www.googleapis.com/youtube/v3';

    private readonly UploadPassBudget $pass;

    public function __construct(private readonly YouTubeQuota $quota = new YouTubeQuota)
    {
        $this->pass = new UploadPassBudget(Network::YouTube, minSliceDefault: 10);
    }

    public function quota(): YouTubeQuota
    {
        return $this->quota;
    }

    public function pass(): UploadPassBudget
    {
        return $this->pass;
    }

    /**
     * `GET` de un recurso de la API; lanza la excepcion de dominio si la respuesta no es correcta.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(SocialAccount $account, string $resource, array $query): array
    {
        $response = $this->send($account, fn (PendingRequest $http) => $http->get(self::API.'/'.$resource, $query), false);

        if (! $response->successful()) {
            throw $this->exceptionFor($response);
        }

        return $response->json() ?? [];
    }

    /** @param \Closure(PendingRequest): Response $call */
    public function send(SocialAccount $account, \Closure $call, bool $json = true): Response
    {
        $http = Http::timeout($this->pass->callTimeout((float) config('social.http_timeout', 15)))->acceptJson()->withToken((string) $account->access_token);

        try {
            return $call($json ? $http->asJson() : $http);
        } catch (ConnectionException) {
            throw new TransientPublishException('No se pudo contactar con YouTube (tiempo agotado o red).', 'red');
        }
    }

    /** Peticion de subida (cuerpo binario): timeout propio de la subida, acotado a lo que queda de la pasada. */
    public function uploadRequest(SocialAccount $account): PendingRequest
    {
        return Http::timeout($this->pass->callTimeout((float) config('social.youtube.upload_timeout', 60)))->withToken((string) $account->access_token);
    }

    public function exceptionFor(Response $response): PublishException|ReconnectRequiredException
    {
        $status = $response->status();
        $reason = (string) ($response->json('error.errors.0.reason') ?? $response->json('error.status') ?? '');
        $message = (string) ($response->json('error.message') ?? 'respuesta inesperada');

        Log::warning('youtube.publish_error', ['status' => $status, 'reason' => $reason]);

        if ($status === 401) {
            return new ReconnectRequiredException('YouTube rechazo las credenciales ('.($reason ?: $status).'). Reconecta el canal.');
        }

        // `rateLimitExceeded` es un tope de ritmo POR USUARIO y corto: se reintenta con espera, no agota el cupo del dia.
        if (in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true)) {
            return new TransientPublishException('YouTube limito temporalmente el ritmo de peticiones; se reintentara.', 'youtube_limite_ritmo');
        }

        if (in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true)) {
            $this->quota->markExhausted();

            return new QuotaExceededException('YouTube informa de que se agoto el cupo de la API ('.$reason.').', $this->quota->resetsAt());
        }

        if ($reason === 'uploadLimitExceeded') {
            return new QuotaExceededException('El canal alcanzo su limite diario de subidas de YouTube.', now()->addDay());
        }

        if ($status === 403) {
            return new PermanentPublishException('YouTube no permite publicar con esta cuenta ('.($reason ?: $message).'). Revisa los permisos y vuelve a conectar el canal.', 'permisos');
        }

        if ($status >= 500 || $status === 429) {
            return new TransientPublishException('YouTube no esta disponible temporalmente ('.($reason ?: $status).').', 'youtube_no_disponible');
        }

        return new PermanentPublishException('YouTube rechazo la publicacion: '.$message, 'rechazada');
    }
}
