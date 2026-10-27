<?php

namespace App\Connectors\Contracts;

/**
 * Lectores de metricas cuya API devuelve VARIAS publicaciones por llamada (Bluesky: `getPosts`, 25 URIs). El colector las pide en
 * lote en lugar de una llamada por publicacion.
 */
interface BatchPostInsights
{
    /**
     * @param  list<string>  $externalIds  25 como maximo por llamada (el lector parte los lotes mayores).
     * @return array<string, array<string, int|float|null>> id externo => metricas normalizadas (`Metric::POST`)
     */
    public function postsInsights(array $externalIds): array;
}
