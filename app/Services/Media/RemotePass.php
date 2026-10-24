<?php

namespace App\Services\Media;

/** Resultado de UNA pasada de descarga de una `RemoteSource`. */
final class RemotePass
{
    public function __construct(
        /** El servidor ignoro `Range` en una reanudacion (200 con offset > 0): el cuerpo no vale y hay que reiniciar. */
        public readonly bool $restart,
        /** El fichero esta entero en el parcial. */
        public readonly bool $complete,
        /** El servidor admite `Range` (respondio 206 o anuncia `Accept-Ranges: bytes`). */
        public readonly bool $ranges,
        /** Tamano total del recurso remoto, si lo declara. */
        public readonly ?int $total,
        /** Validador para `If-Range` (ETag fuerte o Last-Modified). */
        public readonly ?string $validator,
        /** `Content-Type` sin parametros, en minusculas. */
        public readonly ?string $mime,
        /** Bytes escritos por esta pasada. */
        public readonly int $written,
    ) {}
}
