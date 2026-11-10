<?php

namespace App\Services\Studio\Ai;

/** Peticion normalizada a un proveedor: `kind` generate | edit | outpaint | variation. Los bytes de entrada son PNG ya acotados. */
final class AiImageRequest
{
    public function __construct(
        public readonly string $kind,
        public readonly string $prompt,
        public readonly string $aspect,
        public readonly int $count,
        public readonly ?string $image = null,
        public readonly ?string $mask = null,
        public readonly ?float $deadline = null,
    ) {}
}
