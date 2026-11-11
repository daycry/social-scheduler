<?php

namespace App\Services\Studio\Ai;

use App\Services\Media\UnsafeUrlException;
use App\Services\Media\UrlGuard;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class AbstractImageProvider implements ImageProvider
{
    /** @var array<string, mixed> */
    protected array $config;

    private readonly string $key;

    public function __construct(#[\SensitiveParameter] string $key, array $config)
    {
        $this->key = trim($key);
        $this->config = $config;
    }

    public function configured(): bool
    {
        return $this->key !== '';
    }

    public function model(): string
    {
        return (string) $this->config['model'];
    }

    public function label(): string
    {
        return (string) ($this->config['label'] ?? $this->id());
    }

    /** La clave solo sale hacia la cabecera de autenticacion de la peticion del adaptador. */
    protected function secret(): string
    {
        return $this->key;
    }

    /** Una llamada nunca dura mas que `timeout` ni que lo que queda del presupuesto del job. */
    protected function http(?float $deadline = null): PendingRequest
    {
        $timeout = (int) config('studio.ai.timeout', 90);
        if ($deadline !== null) {
            $timeout = max(5, min($timeout, (int) floor($deadline - microtime(true))));
        }

        return Http::timeout($timeout)->connectTimeout(15)->acceptJson();
    }

    /** @param callable(): Response $send */
    protected function send(callable $send): Response
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            // Solo un fallo ANTERIOR al envio (DNS, conexion, TLS) es seguro de repetir. Un timeout de lectura o un corte tras enviar
            // es ambiguo: el proveedor pudo generar y cobrar la imagen, y repetir el POST la cobraria otra vez (CWE-770).
            throw $this->beforeSend($e->getMessage()) ? new AiProviderException('timeout', true) : new AiProviderException('timeout_ambiguous');
        }
        if ($response->successful()) {
            return $response;
        }
        $status = $response->status();
        $code = $this->classify($status, $response->json());
        // Solo el estado y el codigo: ni el cuerpo ni la clave.
        Log::warning('studio.ai_provider_failed', ['provider' => $this->id(), 'status' => $status, 'code' => $code]);
        $retryable = in_array($code, ['rate_limited', 'server'], true);
        $after = $retryable ? max(0, min((int) config('studio.ai.max_retry_after', 30), (int) $response->header('Retry-After'))) : 0;

        throw new AiProviderException($code, $retryable, $after, $status);
    }

    private function beforeSend(string $message): bool
    {
        if (preg_match('/cURL error (\d+)/', $message, $m) !== 1) {
            return false;
        }
        $errno = (int) $m[1];

        return in_array($errno, [5, 6, 7, 35, 51, 58, 59, 60, 77], true)
            || ($errno === 28 && preg_match('/Connection timed out|Resolving timed out|SSL connection timeout/i', $message) === 1);
    }

    /** @param array<string, mixed>|null $body */
    abstract protected function classify(int $status, ?array $body): string;

    /**
     * Valida un base64 devuelto por el proveedor: tamaño antes de decodificar, decodificacion estricta, firma real de imagen y dimensiones.
     *
     * @throws AiProviderException
     */
    protected function image(string $base64): string
    {
        $max = (int) config('studio.ai.max_output_bytes', 30 * 1024 * 1024);
        if (strlen($base64) > (int) ceil($max * 4 / 3) + 8) {
            throw new AiProviderException('output_too_large');
        }
        $bytes = base64_decode($base64, true);

        return $this->validated($bytes === false ? '' : $bytes);
    }

    /** @throws AiProviderException */
    protected function validated(string $bytes): string
    {
        if (strlen($bytes) > (int) config('studio.ai.max_output_bytes', 30 * 1024 * 1024)) {
            throw new AiProviderException('output_too_large');
        }
        if (self::mime($bytes) === null) {
            throw new AiProviderException('invalid_response');
        }
        $info = @getimagesizefromstring($bytes);
        if (! $info || $info[0] < 1 || $info[1] < 1 || (int) config('studio.ai.max_output_pixels', 36000000) < $info[0] * $info[1]) {
            throw new AiProviderException('invalid_response');
        }

        return $bytes;
    }

    /** Tipo real por firma (magic bytes), nunca por la cabecera ni la extension que declare el proveedor. */
    public static function mime(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            strlen($bytes) > 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            default => null,
        };
    }

    /** Si el proveedor devuelve una URL, se descarga con la guardia SSRF (IP publica fijada, redirecciones revalidadas) y tope de bytes. */
    protected function download(string $url): string
    {
        $max = (int) config('studio.ai.max_output_bytes', 30 * 1024 * 1024);
        $guard = app(UrlGuard::class);
        try {
            $response = $guard->follow($url, fn ($validated) => $guard->client($validated)->timeout((int) config('studio.ai.timeout', 120))
                ->withOptions(['progress' => fn ($total, $now) => $now > $max])->get($validated->url));
        } catch (UnsafeUrlException|ConnectionException|GuzzleException) {
            throw new AiProviderException('invalid_response');
        }
        if (! $response->successful()) {
            throw new AiProviderException('invalid_response');
        }

        return $this->validated($response->body());
    }
}
