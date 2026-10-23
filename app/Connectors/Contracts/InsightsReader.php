<?php

namespace App\Connectors\Contracts;

use App\Models\PostTarget;
use App\Models\SocialAccount;
use Carbon\CarbonInterface;

/**
 * Capacidad de leer metricas de la cuenta y de sus publicaciones (fase 7).
 *
 * Contrato comun de los colectores: devuelven SIEMPRE el mapa completo de claves canonicas
 * (App\Analytics\Metric) con `null` en lo que la API no ofrece ("no disponible"), nunca 0.
 * Los errores siguen la jerarquia de los conectores: ReconnectRequiredException (credenciales),
 * TransientPublishException (reintentable) y, para metricas concretas no admitidas, `null` en vez de excepcion.
 */
interface InsightsReader
{
    /** @return array<string, int|float|null> Metricas normalizadas de cuenta del dia indicado. */
    public function accountInsights(SocialAccount $account, CarbonInterface $day): array;

    /** ¿Se pueden pedir metricas de este destino? (p. ej. TikTok solo con ID de video y permisos concedidos). */
    public function canReadPost(PostTarget $target): bool;

    /** @return array<string, int|float|null> Metricas acumuladas de la publicacion (claves `Metric::POST`). */
    public function postInsights(SocialAccount $account, string $externalId): array;
}
