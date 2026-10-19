<?php

namespace App\Connectors\Google;

use App\Analytics\Metric;
use App\Connectors\Contracts\InsightsReader;
use App\Connectors\Exceptions\PermanentPublishException;
use App\Connectors\Exceptions\TransientPublishException;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Colector de YouTube: YouTube Analytics API v2 (`yt-analytics.readonly`) para flujos diarios del canal y de
 * los videos, y YouTube Data API (`channels.list?part=statistics`, scope `youtube.readonly`) para el numero de
 * suscriptores y videos (stock).
 *
 * Metricas de Analytics usadas (documentadas en channel_reports): views, estimatedMinutesWatched, likes,
 * comments, shares, subscribersGained, subscribersLost. La API NO ofrece impresiones ni alcance: quedan `null`.
 * Sin filas para el dia (latencia de procesado de 1-3 dias) el flujo es `null`, no 0: el job diario lo reintenta.
 * `subscriberCount` es `null` si el canal oculta sus suscriptores (`hiddenSubscriberCount`).
 *
 * Un fallo de la API de Analytics (scope no concedido, API no habilitada, metrica no admitida) deja el flujo en
 * `null` ("no disponible") sin romper los stocks de la Data API.
 */
class Analytics implements InsightsReader
{
    private const REPORTS_URL = 'https://youtubeanalytics.googleapis.com/v2/reports';

    private const CHANNELS_URL = 'https://www.googleapis.com/youtube/v3/channels';

    private const STOCK_MAX_AGE_DAYS = 2;

    private const CHANNEL_METRICS = [
        'views' => Metric::VIEWS,
        'estimatedMinutesWatched' => Metric::WATCH_MINUTES,
        'likes' => Metric::LIKES,
        'comments' => Metric::COMMENTS,
        'shares' => Metric::SHARES,
        'subscribersGained' => Metric::FOLLOWERS_GAINED,
        'subscribersLost' => Metric::FOLLOWERS_LOST,
    ];

    public function accountInsights(SocialAccount $account, CarbonInterface $day): array
    {
        $out = Metric::blankAccount();
        $token = (string) $account->access_token;
        $date = $day->copy()->startOfDay()->format('Y-m-d');

        $row = $this->report($token, [
            'ids' => 'channel==MINE',
            'startDate' => $date,
            'endDate' => $date,
            'metrics' => implode(',', array_keys(self::CHANNEL_METRICS)),
        ]);

        foreach ($row as $name => $value) {
            if (isset(self::CHANNEL_METRICS[$name])) {
                $out[self::CHANNEL_METRICS[$name]] = $value;
            }
        }

        $out[Metric::INTERACTIONS] = $this->interactions($out);

        if ($day->copy()->startOfDay()->gte(now()->startOfDay()->subDays(self::STOCK_MAX_AGE_DAYS))) {
            try {
                $stats = $this->channelStatistics($token);
            } catch (PermanentPublishException) {
                $stats = [];
            }

            $out[Metric::FOLLOWERS] = ($stats['hiddenSubscriberCount'] ?? false) ? null : $this->num($stats['subscriberCount'] ?? null);
            $out[Metric::VIDEO_COUNT] = $this->num($stats['videoCount'] ?? null);
        }

        return $out;
    }

    public function canReadPost(PostTarget $target): bool
    {
        return $target->external_id !== null && $target->external_id !== '';
    }

    public function postInsights(SocialAccount $account, string $externalId): array
    {
        $out = Metric::blankPost();

        // Acumulado desde la publicacion: un informe sin dimension de dia filtrado por video.
        $row = $this->report((string) $account->access_token, [
            'ids' => 'channel==MINE',
            'startDate' => now()->subYears(2)->format('Y-m-d'),
            'endDate' => now()->format('Y-m-d'),
            'metrics' => 'views,estimatedMinutesWatched,likes,comments,shares',
            'filters' => 'video=='.$externalId,
        ]);

        $out[Metric::VIEWS] = $row['views'] ?? null;
        $out[Metric::WATCH_MINUTES] = $row['estimatedMinutesWatched'] ?? null;
        $out[Metric::LIKES] = $row['likes'] ?? null;
        $out[Metric::COMMENTS] = $row['comments'] ?? null;
        $out[Metric::SHARES] = $row['shares'] ?? null;
        $out[Metric::INTERACTIONS] = $this->interactions($out);

        return $out;
    }

    /** likes + comments + shares si hay alguno disponible; si no, `null`. */
    private function interactions(array $m): int|float|null
    {
        $parts = array_filter([$m[Metric::LIKES] ?? null, $m[Metric::COMMENTS] ?? null, $m[Metric::SHARES] ?? null], fn ($v) => $v !== null);

        return $parts === [] ? null : array_sum($parts);
    }

    /**
     * Una fila del informe como `[metrica => valor]`; sin filas o error permanente (scope no concedido) = `[]`.
     *
     * @param  array<string, string>  $query
     * @return array<string, int|float>
     */
    private function report(string $token, array $query): array
    {
        try {
            $json = $this->get(self::REPORTS_URL, $query, $token);
        } catch (PermanentPublishException) {
            return [];
        }

        $headers = array_column($json['columnHeaders'] ?? [], 'name');
        $row = $json['rows'][0] ?? null;

        if (! is_array($row) || $headers === []) {
            return [];
        }

        $out = [];

        foreach ($headers as $i => $name) {
            if (isset($row[$i]) && is_numeric($row[$i])) {
                $out[$name] = $row[$i] + 0;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function channelStatistics(string $token): array
    {
        $json = $this->get(self::CHANNELS_URL, ['part' => 'statistics', 'mine' => 'true'], $token);

        return $json['items'][0]['statistics'] ?? [];
    }

    /** @return array<string, mixed> */
    private function get(string $url, array $query, string $token): array
    {
        try {
            $response = Http::timeout((int) config('social.http_timeout', 15))->acceptJson()->withToken($token)->get($url, $query);
        } catch (ConnectionException) {
            throw new TransientPublishException('No se pudo contactar con YouTube (tiempo agotado o red).', 'red');
        }

        return $this->parse($response);
    }

    /** @return array<string, mixed> */
    private function parse(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }

        $reason = (string) ($response->json('error.errors.0.reason') ?? $response->json('error.status') ?? $response->status());
        Log::warning('youtube.analytics_error', ['status' => $response->status(), 'reason' => $reason]);

        // 401: el access token pudo caducar entre renovaciones; el refresco lo renueva (o escala a reconexion).
        if ($response->serverError() || $response->status() === 401 || $response->status() === 429
            || in_array($reason, ['quotaExceeded', 'rateLimitExceeded', 'userRateLimitExceeded', 'backendError'], true)) {
            throw new TransientPublishException('YouTube no esta disponible temporalmente ('.$reason.').', 'youtube_no_disponible');
        }

        // 403 insufficient scope / API no habilitada, 400 metrica no admitida: "no disponible", no rompe el resto.
        throw new PermanentPublishException('YouTube Analytics rechazo la consulta ('.$reason.').', $reason);
    }

    private function num(mixed $v): int|float|null
    {
        return is_numeric($v) ? $v + 0 : null;
    }
}
