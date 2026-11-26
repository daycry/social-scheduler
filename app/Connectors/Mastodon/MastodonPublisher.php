<?php

namespace App\Connectors\Mastodon;

use App\Connectors\Contracts\Publisher;
use App\Connectors\Data\PublishMedia;
use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\ReconnectRequiredException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Connectors\Support\StepBudget;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use App\Support\TextLength;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Publicacion en Mastodon (T-87): `POST /api/v2/media` por cada imagen (con su texto alternativo como `description`; un 202 se sondea con
 * `GET /api/v1/media/:id` dentro del presupuesto del paso) y `POST /api/v1/statuses` con `media_ids[]`, la visibilidad elegida (`public` por defecto) y una
 * `Idempotency-Key` determinista por destino e intento (la instancia la recuerda una hora: un reintento tras un timeout devuelve el estado ya creado en
 * lugar de duplicarlo). Los ids de las imagenes ya subidas se guardan en el progreso del destino para no subirlas otra vez al reintentar.
 *
 * Los limites son los de la instancia de ESA cuenta (`MastodonLimits`); si tienen mas de `limits_ttl_hours` se vuelven a leer antes de publicar.
 */
class MastodonPublisher implements Publisher
{
    public function __construct(private readonly MastodonClient $client) {}

    public function publish(SocialAccount $account, PublishRequest $request): PublishResult
    {
        if ($request->type !== ContentType::MastodonPost) {
            throw new PermanentPublishException('Mastodon no admite este tipo de contenido.', 'tipo_no_admitido');
        }

        $token = (string) $account->access_token;
        $base = (string) ($account->meta['instance'] ?? '');
        if ($token === '' || $base === '') {
            throw new ReconnectRequiredException('La cuenta de Mastodon no tiene instancia o token guardados: vuelve a conectarla.');
        }

        try {
            $limits = $this->limits($account, $base);
            $this->assertWithinLimits($request, $limits);

            $mediaIds = $this->uploadImages($base, $token, $request, $limits);
            $status = $this->client->createStatus($base, $token, $this->body($request, $mediaIds), self::idempotencyKey($request));
            $request->budget()->spent();
        } catch (MastodonException $e) {
            throw MastodonErrors::translate($e);
        }

        $id = (string) ($status['id'] ?? '');
        if ($id === '') {
            throw new PermanentPublishException('Mastodon no devolvió el identificador de la publicación.', 'sin_id');
        }

        // La URL la escribe una instancia ajena y acaba en un enlace de la app: solo https y del host de la instancia (`url`, o `uri` si `url` no vale).
        $host = (string) parse_url($base, PHP_URL_HOST);
        $url = MastodonClient::safeUrl($status['url'] ?? null, $host) ?? MastodonClient::safeUrl($status['uri'] ?? null, $host);

        return PublishResult::published($id, $url, ['created_at' => $status['created_at'] ?? null]);
    }

    /** La instancia no permite consultar un estado por su `Idempotency-Key`: un reintento no publica de mas porque `publish` la repite. */
    public function findPublished(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        return null;
    }

    /**
     * Clave de idempotencia: la misma para todos los reintentos del MISMO contenido de un destino y de un intento de publicacion (`since` es el inicio de su
     * ventana, fijo mientras dura) y distinta entre destinos. Lleva un hash del contenido (texto, visibilidad e imagenes con su alt): si se edita tras un
     * timeout ambiguo, la instancia (que recuerda la clave una hora) no devuelve el estado viejo y la edicion se publica; el coste es que, si el envio
     * anterior si llego a salir, queda tambien el estado antiguo en la instancia, visible para el usuario. Nunca contiene el token.
     */
    public static function idempotencyKey(PublishRequest $request): string
    {
        $content = json_encode([
            trim((string) $request->text),
            MastodonPostOptions::visibility($request->options),
            array_map(fn (PublishMedia $m) => [$m->id, trim((string) $m->alt)], $request->media),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', 'mastodon-status|'.$request->targetId.'|'.($request->since?->getTimestamp() ?? 0).'|'.hash('sha256', (string) $content));
    }

    /**
     * Limites de la cuenta, releidos de la instancia si el dato es viejo. Si la lectura falla se siguen usando los guardados (o los por defecto).
     *
     * @return array<string, mixed>
     */
    private function limits(SocialAccount $account, string $base): array
    {
        if (MastodonLimits::isStale($account)) {
            try {
                $fresh = MastodonLimits::fromInstance($this->client->instance($base));
                $account->forceFill(['meta' => array_merge((array) $account->meta, ['limits' => $fresh])])->save();
            } catch (MastodonException) {
                // Sin lectura se publica con lo que haya; el siguiente intento vuelve a probar.
            }
        }

        return MastodonLimits::forAccount($account);
    }

    /** @param array<string, mixed> $limits */
    private function assertWithinLimits(PublishRequest $request, array $limits): void
    {
        if (count($request->media) > $limits['max_media_attachments']) {
            throw new PermanentPublishException('Tu instancia de Mastodon admite como máximo '.$limits['max_media_attachments'].' imágenes por publicación.', 'demasiadas_imagenes');
        }
        if (TextLength::count($request->text, TextLength::MASTODON, $limits['characters_reserved_per_url']) > $limits['max_characters']) {
            throw new PermanentPublishException('El texto supera los '.$limits['max_characters'].' caracteres que admite tu instancia de Mastodon.', 'texto_largo');
        }
    }

    /**
     * Sube las imagenes que faltan y espera a que estén listas; devuelve los ids en orden. Los ya subidos (progreso del destino) no se repiten.
     *
     * @param  array<string, mixed>  $limits
     * @return list<string>
     */
    private function uploadImages(string $base, #[\SensitiveParameter] string $token, PublishRequest $request, array $limits): array
    {
        $ids = array_values(array_filter((array) ($request->progress['media_ids'] ?? []), fn ($id) => is_string($id) && $id !== ''));
        $budget = $request->budget();

        foreach ($request->media as $i => $media) {
            // Un id guardado en el progreso caduca (la instancia limpia las subidas sin usar): si ya no existe (404) se vuelve a subir.
            if (isset($ids[$i])) {
                try {
                    $this->awaitReady($base, $token, $ids[$i], $budget);

                    continue;
                } catch (MastodonException $e) {
                    if ($e->status !== 404) {
                        throw $e;
                    }
                    unset($ids[$i]);
                }
            }

            $this->assertTime($budget);
            $uploaded = $this->upload($base, $token, $media, $limits);
            $budget->spent();
            $ids[$i] = $uploaded['id'];
            ksort($ids);
            $request->checkpoint(null, ['media_ids' => $ids]);

            if (! $uploaded['ready']) {
                $this->awaitReady($base, $token, $ids[$i], $budget);
            }
        }

        return array_slice($ids, 0, count($request->media));
    }

    /**
     * @param  array<string, mixed>  $limits
     * @return array{id: string, ready: bool}
     */
    private function upload(string $base, #[\SensitiveParameter] string $token, PublishMedia $media, array $limits): array
    {
        if ($media->isVideo() || $media->disk === null || $media->path === null) {
            throw new PermanentPublishException('Mastodon solo admite imágenes en este tipo de publicación.', 'tipo_no_admitido');
        }
        if (! in_array($media->mime, $limits['image_mime_types'], true)) {
            throw new PermanentPublishException('Tu instancia de Mastodon no admite el formato de una de las imágenes ('.$media->mime.').', 'formato_no_admitido');
        }
        if ((int) $media->size > $limits['image_size_limit']) {
            throw new PermanentPublishException('Una imagen pesa más de lo que admite tu instancia de Mastodon.', 'archivo_grande');
        }

        try {
            $bytes = (string) Storage::disk($media->disk)->get($media->path);
        } catch (Throwable) {
            throw new PermanentPublishException('No se pudo leer una de las imágenes.', 'media_ilegible');
        }
        if ($bytes === '') {
            throw new PermanentPublishException('No se pudo leer una de las imágenes.', 'media_ilegible');
        }

        $alt = trim((string) $media->alt);
        if ($limits['description_limit'] !== null) {
            $alt = mb_substr($alt, 0, $limits['description_limit']);
        }

        $result = $this->client->uploadMedia($base, $token, $bytes, $media->mime, basename($media->path), $alt);
        $id = (string) ($result['media']['id'] ?? '');
        if ($id === '') {
            throw new PermanentPublishException('Mastodon no devolvió el identificador de la imagen subida.', 'sin_id');
        }

        // 200 = lista; 202 = la instancia sigue procesandola (la URL llega nula).
        return ['id' => $id, 'ready' => $result['status'] === 200];
    }

    /** Sondea `GET /api/v1/media/:id` hasta que la imagen esté lista (200); sin tiempo o intentos, se reintenta el destino con los ids ya guardados. */
    private function awaitReady(string $base, #[\SensitiveParameter] string $token, string $id, StepBudget $budget): void
    {
        $attempts = max(1, (int) config('social.mastodon.media_poll_attempts', 5));

        for ($n = 1; $n <= $attempts; $n++) {
            $this->assertTime($budget);
            $state = $this->client->media($base, $token, $id);
            $budget->spent();

            if ($state['status'] === 200) {
                return;
            }
            if ($n < $attempts) {
                // La espera nunca se come el presupuesto del paso: se deja sitio para la siguiente llamada (`assertTime` corta si ya no cabe).
                $room = max(0.0, $budget->remaining() - (float) config('social.http_timeout', 15));
                usleep((int) (min(max(0, (int) config('social.mastodon.media_poll_delay_ms', 1500)) / 1000, $room) * 1_000_000));
            }
        }

        throw new TransientPublishException('Mastodon sigue procesando una imagen; se reintentará.', 'imagen_procesando');
    }

    /** Sin tiempo para otra llamada dentro del presupuesto del paso: se reintenta en lugar de morir por el timeout del job. */
    private function assertTime(StepBudget $budget): void
    {
        if (! $budget->allows()) {
            throw new TransientPublishException('Sin tiempo para seguir publicando en Mastodon en este intento; se reintentará.', 'sin_tiempo');
        }
    }

    /**
     * @param  list<string>  $mediaIds
     * @return array<string, mixed>
     */
    private function body(PublishRequest $request, array $mediaIds): array
    {
        return array_filter([
            'status' => trim((string) $request->text),
            'media_ids' => $mediaIds !== [] ? $mediaIds : null,
            'visibility' => MastodonPostOptions::visibility($request->options),
        ], fn ($v) => $v !== null && $v !== '');
    }
}
