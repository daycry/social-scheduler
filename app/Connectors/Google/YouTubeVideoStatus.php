<?php

namespace App\Connectors\Google;

use App\Connectors\Data\PublishRequest;
use App\Connectors\Data\PublishResult;
use App\Connectors\Exceptions\PublishException;
use App\Enums\ContentType;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Log;

/** Consulta el vídeo publicado y recupera su ID a partir del tag propio del destino. */
class YouTubeVideoStatus
{
    /** Motivos de rechazo/fallo de YouTube -> complemento legible. */
    private const REASONS = [
        'copyright' => 'por derechos de autor',
        'duplicate' => 'porque el video ya estaba subido',
        'inappropriate' => 'por contenido inapropiado',
        'length' => 'por su duracion',
        'termsOfUse' => 'por incumplir las condiciones de uso',
        'trademark' => 'por una marca registrada',
        'claim' => 'por una reclamacion de derechos',
        'legal' => 'por motivos legales',
        'uploaderAccountClosed' => 'porque la cuenta esta cerrada',
        'uploaderAccountSuspended' => 'porque la cuenta esta suspendida',
        'codec' => 'por un codec no admitido',
        'unsupportedCodec' => 'por un codec no admitido',
        'conversion' => 'por un error de conversion',
        'emptyFile' => 'porque el archivo esta vacio',
        'invalidFile' => 'porque el archivo no es valido',
        'tooSmall' => 'porque el archivo es demasiado pequeno',
        'uploadAborted' => 'porque la subida se aborto',
    ];

    public function __construct(private readonly YouTubeClient $client) {}

    public function poll(SocialAccount $account, string $videoId, ContentType $type): PublishResult
    {
        $data = $this->client->get($account, 'videos', ['part' => 'status,processingDetails', 'id' => $videoId]);
        $item = $data['items'][0] ?? null;

        if ($item === null) {
            return PublishResult::processing($videoId); // aun no visible; el sondeo vence por tiempo
        }

        $status = $item['status'] ?? [];
        $upload = (string) ($status['uploadStatus'] ?? '');
        $processing = (string) ($item['processingDetails']['processingStatus'] ?? '');

        if (in_array($upload, ['failed', 'rejected', 'deleted'], true) || in_array($processing, ['failed', 'terminated'], true)) {
            $reason = (string) ($status['rejectionReason'] ?? $status['failureReason'] ?? $item['processingDetails']['processingFailureReason'] ?? '');
            $why = self::REASONS[$reason] ?? ($reason !== '' ? "({$reason})" : '');

            return PublishResult::failed(match ($upload) {
                'rejected' => 'YouTube rechazo el video '.($why !== '' ? $why : 'tras revisarlo').'.',
                'deleted' => 'El video se elimino de YouTube antes de terminar de procesarse.',
                default => 'YouTube no pudo procesar el video'.($why !== '' ? ' '.$why : '').'.',
            });
        }

        if ($upload === 'processed' || $processing === 'succeeded') {
            return PublishResult::published($videoId, $this->watchUrl($videoId, $type));
        }

        return PublishResult::processing($videoId);
    }

    public function findByTarget(SocialAccount $account, PublishRequest $request): ?PublishResult
    {
        $tag = YouTubeVideoMetadata::targetTag($request->targetId);
        $found = null;

        try {
            // La lista de subidas de un canal es la playlist cuyo ID sustituye el prefijo UC por UU. Se pagina (de la mas
            // reciente a la mas antigua) hasta pasar `since`, sin pagina siguiente o `lookup_max_pages`; cada pagina cuesta
            // 1 ud (playlistItems.list) + 1 ud (videos.list): tope 2 x paginas (revision F4-5 i2 N5).
            $pageToken = null;
            $maxPages = max(1, (int) config('social.youtube.lookup_max_pages', 3));

            for ($page = 0; $page < $maxPages && $found === null; $page++) {
                $query = ['part' => 'snippet', 'maxResults' => 50, 'playlistId' => 'UU'.substr($account->external_id, 2)];
                if ($pageToken !== null) {
                    $query['pageToken'] = $pageToken;
                }

                $recent = $this->client->get($account, 'playlistItems', $query);

                $ids = [];
                $reachedOlder = false;
                foreach ($recent['items'] ?? [] as $item) {
                    $snippet = $item['snippet'] ?? [];
                    $when = isset($snippet['publishedAt']) ? strtotime((string) $snippet['publishedAt']) : false;
                    $videoId = (string) ($snippet['resourceId']['videoId'] ?? '');

                    if ($videoId !== '' && ($request->since === null || ($when !== false && $when >= $request->since->getTimestamp()))) {
                        $ids[] = $videoId;
                    } elseif ($request->since !== null && $when !== false && $when < $request->since->getTimestamp()) {
                        $reachedOlder = true;
                    }
                }

                if ($ids !== []) {
                    // Los tags no vienen en la lista de subidas: se piden (1 ud de cuota) y solo cuenta el video con el tag de ESTE destino.
                    $videos = $this->client->get($account, 'videos', ['part' => 'snippet', 'id' => implode(',', $ids)]);

                    foreach ($videos['items'] ?? [] as $video) {
                        $id = (string) ($video['id'] ?? '');

                        if ($id !== '' && in_array($tag, (array) ($video['snippet']['tags'] ?? []), true)) {
                            $found = $id;
                            break;
                        }
                    }
                }

                $pageToken = $recent['nextPageToken'] ?? null;

                if ($reachedOlder || ! is_string($pageToken) || $pageToken === '') {
                    break;
                }
            }
        } catch (PublishException $e) {
            Log::notice('youtube.lookup_failed', ['account_id' => $account->id, 'code' => $e->errorCode]);

            return null;
        }

        if ($found === null) {
            return null;
        }

        return $this->poll($account, $found, $request->type);
    }

    private function watchUrl(string $id, ContentType $type): string
    {
        return $type === ContentType::YouTubeShort ? "https://www.youtube.com/shorts/{$id}" : "https://www.youtube.com/watch?v={$id}";
    }
}
