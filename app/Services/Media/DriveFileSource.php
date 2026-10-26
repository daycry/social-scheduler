<?php

namespace App\Services\Media;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Descarga de un fichero de Google Drive (C-04b) con el token OAuth del usuario (`drive.file`). Comparte el pipeline de la URL
 * (`UrlSource`: `UrlGuard`, IP fijada, `Range`, pasadas), con tres diferencias:
 *  - la URL la construye ESTA clase con el `fileId` validado: host fijo `www.googleapis.com`, `files.get?alt=media`;
 *  - el token `Authorization: Bearer` solo se envia a ese host y por HTTPS/443; una redireccion (p. ej. a `*.googleusercontent.com`) a
 *    cualquier otro destino se rechaza ANTES de hacer la peticion, asi el token nunca sale hacia otro host;
 *  - un 401/403/404 significa «token caducado o fichero que ya no se puede leer»: «Vuelve a elegir el fichero en Drive».
 */
class DriveFileSource extends UrlSource
{
    public const HOST = 'www.googleapis.com';

    public const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    public const EXPIRED = 'El acceso a Google Drive ha caducado o el fichero ya no está disponible. Vuelve a elegir el fichero en Drive.';

    public function __construct(UrlGuard $guard, private readonly string $fileId, #[\SensitiveParameter] private readonly string $token)
    {
        if (! self::validFileId($fileId)) {
            throw new InvalidArgumentException('El identificador del fichero de Drive no es válido.');
        }

        if (trim($token) === '' || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7f]/', $token)) {
            throw new InvalidArgumentException(self::EXPIRED);
        }

        parent::__construct($guard, 'https://'.self::HOST.'/drive/v3/files/'.$fileId.'?alt=media');
    }

    public static function validFileId(string $fileId): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,128}$/', $fileId);
    }

    public function name(): string
    {
        return 'drive-'.$this->fileId;
    }

    /** Solo el `fileId`: la URL de descarga no lleva secretos, pero el `fileId` es lo util para localizar el origen. */
    public function reference(): string
    {
        return $this->fileId;
    }

    /**
     * Comprueba el token con `tokeninfo` (`aud` = nuestro client id y scope `drive.file`) y pide los metadatos del fichero. El token va en
     * el cuerpo de un POST (no en la URL) y nunca se registra.
     *
     * @return array{name: string, mime: string, size: ?int}
     *
     * @throws InvalidArgumentException con un mensaje para el usuario si algo no vale.
     */
    public function inspect(): array
    {
        $this->verifyToken();

        try {
            $res = Http::withoutRedirecting()->timeout(15)->withToken($this->token)->acceptJson()
                ->get('https://'.self::HOST.'/drive/v3/files/'.$this->fileId, ['fields' => 'name,mimeType,size']);
        } catch (ConnectionException) {
            throw new InvalidArgumentException('No se pudo contactar con Google Drive; inténtalo de nuevo.');
        }

        if (in_array($res->status(), [401, 403, 404], true)) {
            throw new InvalidArgumentException(self::EXPIRED);
        }

        if (! $res->successful()) {
            throw new InvalidArgumentException('Google Drive no pudo leer el fichero; inténtalo de nuevo.');
        }

        $mime = strtolower((string) $res->json('mimeType'));

        if ($mime === '' || ! in_array($mime, array_merge(...array_values((array) config('media.allowed_mimes'))), true)) {
            throw new InvalidArgumentException('Tipo de fichero no admitido. Usa JPG, PNG, WebP, MP4 o MOV.');
        }

        $size = $res->json('size');

        return [
            'name' => (string) ($res->json('name') ?: $this->name()),
            'mime' => $mime,
            'size' => is_numeric($size) ? (int) $size : null,
        ];
    }

    private function verifyToken(): void
    {
        $clientId = (string) config('services.google.client_id');

        try {
            $res = Http::withoutRedirecting()->timeout(15)->acceptJson()->asForm()->post(self::TOKENINFO_URL, ['access_token' => $this->token]);
        } catch (ConnectionException) {
            throw new InvalidArgumentException('No se pudo contactar con Google; inténtalo de nuevo.');
        }

        if ($res->serverError()) {
            throw new InvalidArgumentException('No se pudo contactar con Google; inténtalo de nuevo.');
        }

        $scopes = preg_split('/\s+/', trim((string) $res->json('scope'))) ?: [];

        if (! $res->successful() || $clientId === '' || ! hash_equals($clientId, (string) $res->json('aud')) || ! in_array(self::SCOPE, $scopes, true)) {
            throw new InvalidArgumentException(self::EXPIRED);
        }
    }

    protected function headersFor(ValidatedUrl $v, array $headers): array
    {
        $this->assertAllowed($v);

        return $headers + ['Authorization' => 'Bearer '.$this->token];
    }

    protected function assertAllowed(ValidatedUrl $v): void
    {
        if ($v->host !== self::HOST || $v->scheme !== 'https' || $v->port !== 443) {
            throw new UnsafeUrlException('Google Drive redirigió la descarga a otro servidor. Vuelve a elegir el fichero en Drive.');
        }
    }

    protected function statusMessage(int $status): string
    {
        return in_array($status, [401, 403, 404], true) ? self::EXPIRED : parent::statusMessage($status);
    }
}
