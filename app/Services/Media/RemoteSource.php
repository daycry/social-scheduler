<?php

namespace App\Services\Media;

use Closure;

/** Origen remoto de una importacion (URL publica, fichero de Drive...). Una pasada descarga a un parcial local. */
interface RemoteSource
{
    /**
     * Descarga desde `$offset` (con `Range`) anexando a `$sink` hasta terminar, llegar al tope de bytes o agotar `$deadline`.
     *
     * @param  resource  $sink  Fichero parcial abierto en lectura/escritura; la fuente lo coloca en `$offset`.
     * @param  Closure(?string, ?int): int  $policy  Recibe el tipo y el total declarados; lanza `ImportFailedException` si no valen y devuelve el tope de bytes.
     * @param  int  $cap  Tope de bytes mientras no se conozca el tipo.
     * @param  float  $deadline  Instante (microtime) en que la pasada debe cortar.
     *
     * @throws ImportFailedException|UnsafeUrlException si la descarga no puede continuar nunca.
     */
    public function fetch($sink, int $offset, ?string $validator, Closure $policy, int $cap, float $deadline): RemotePass;

    /** Nombre sugerido para el fichero. */
    public function name(): string;

    /** Referencia que se guarda en `media_assets.source_ref` (sin secretos). */
    public function reference(): string;
}
