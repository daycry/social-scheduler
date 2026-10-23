<?php

namespace App\Analytics;

/**
 * Claves canonicas de las metricas guardadas en los snapshots. Cada colector traduce lo que da su API a estas
 * claves; lo que la API no ofrece se guarda como `null` ("no disponible"), nunca como 0, y no se inventan
 * equivalencias entre redes (p. ej. `views` es lo que cada red llama visualizaciones: en Meta sustituye a las
 * antiguas impresiones, en YouTube son reproducciones).
 */
final class Metric
{
    // Cuenta. `followers` es un STOCK (valor en un instante); el resto son FLUJOS del dia.
    public const FOLLOWERS = 'followers';

    public const FOLLOWERS_GAINED = 'followers_gained';

    public const FOLLOWERS_LOST = 'followers_lost';

    public const VIEWS = 'views';

    public const REACH = 'reach';

    public const PROFILE_VIEWS = 'profile_views';

    public const ACCOUNTS_ENGAGED = 'accounts_engaged';

    public const INTERACTIONS = 'interactions';

    public const LIKES = 'likes';

    public const COMMENTS = 'comments';

    public const SHARES = 'shares';

    public const SAVED = 'saved';

    public const WATCH_MINUTES = 'watch_minutes';

    public const VIDEO_COUNT = 'video_count';

    /** @var list<string> Metricas de cuenta (orden estable en BD y UI). */
    public const ACCOUNT = [
        self::FOLLOWERS, self::FOLLOWERS_GAINED, self::FOLLOWERS_LOST, self::VIEWS, self::REACH, self::PROFILE_VIEWS,
        self::ACCOUNTS_ENGAGED, self::INTERACTIONS, self::LIKES, self::COMMENTS, self::SHARES, self::WATCH_MINUTES,
        self::VIDEO_COUNT,
    ];

    /** @var list<string> Metricas de publicacion. */
    public const POST = [
        self::VIEWS, self::REACH, self::LIKES, self::COMMENTS, self::SHARES, self::SAVED, self::INTERACTIONS, self::WATCH_MINUTES,
    ];

    /** @var list<string> Valores en un instante (no se suman en un periodo: se toma el primero/ultimo). */
    public const STOCKS = [self::FOLLOWERS, self::VIDEO_COUNT];

    /**
     * FLUJOS diarios que cada red PUEDE dar para un dia cerrado. Sirven para decidir si el snapshot de un dia esta
     * completo: un dia con solo stocks (seguidores, videos) NO lo esta. TikTok no ofrece flujos de cuenta (solo stocks).
     *
     * @var array<string, list<string>>
     */
    public const EXPECTED_FLOWS = [
        'instagram' => [self::VIEWS, self::REACH, self::PROFILE_VIEWS, self::ACCOUNTS_ENGAGED, self::INTERACTIONS, self::FOLLOWERS_GAINED],
        'facebook' => [self::VIEWS, self::INTERACTIONS],
        'youtube' => [self::VIEWS, self::WATCH_MINUTES, self::LIKES, self::COMMENTS, self::SHARES, self::INTERACTIONS, self::FOLLOWERS_GAINED, self::FOLLOWERS_LOST],
        'tiktok' => [],
    ];

    /**
     * ¿El snapshot esta completo? Todos los flujos esperados de la red tienen valor; en una red sin flujos (TikTok)
     * basta con que haya algun dato. Los stocks no cuentan: no demuestran que el dia ya este procesado.
     *
     * @param  array<string, mixed>  $metrics
     */
    public static function isComplete(string $network, array $metrics): bool
    {
        $expected = self::EXPECTED_FLOWS[$network] ?? [];

        if ($expected === []) {
            return ! self::isEmpty($metrics);
        }

        foreach ($expected as $key) {
            if (($metrics[$key] ?? null) === null) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, null> Mapa con todas las claves de cuenta a `null` ("no disponible"). */
    public static function blankAccount(): array
    {
        return array_fill_keys(self::ACCOUNT, null);
    }

    /** @return array<string, null> */
    public static function blankPost(): array
    {
        return array_fill_keys(self::POST, null);
    }

    /**
     * Normaliza un mapa de metricas: solo claves conocidas, numericas o `null`.
     *
     * @param  array<string, mixed>  $metrics
     * @param  list<string>  $keys
     * @return array<string, int|float|null>
     */
    public static function clean(array $metrics, array $keys): array
    {
        $out = [];

        foreach ($keys as $key) {
            $v = $metrics[$key] ?? null;
            $out[$key] = is_numeric($v) ? ($v + 0) : null;
        }

        return $out;
    }

    /** ¿Todas las metricas son "no disponible"? */
    public static function isEmpty(array $metrics): bool
    {
        return array_filter($metrics, fn ($v) => $v !== null) === [];
    }
}
