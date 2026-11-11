<?php

namespace App\Services\Studio\Ai;

interface ImageProvider
{
    public function id(): string;

    public function label(): string;

    public function model(): string;

    public function configured(): bool;

    /** @return list<string> subconjunto de generate, edit (con mascara), outpaint, variation */
    public function capabilities(): array;

    /**
     * UNA llamada al proveedor, sin reintentos: los reintentos con espera los decide el servicio.
     *
     * @return list<string> bytes de imagen ya validados (firma, tamaño y dimensiones)
     *
     * @throws AiProviderException
     */
    public function request(AiImageRequest $request): array;
}
