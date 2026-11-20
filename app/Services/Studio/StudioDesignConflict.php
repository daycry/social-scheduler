<?php

namespace App\Services\Studio;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Guardado sobre una revisión obsoleta: 409 con la revisión vigente y quién la guardó, para que la UI ofrezca recargar o guardar como copia. */
class StudioDesignConflict extends HttpException
{
    public function __construct(public readonly int $currentRevision, public readonly ?string $updatedBy, string $lead = 'El diseño cambió en otra sesión')
    {
        parent::__construct(409, $lead.($updatedBy ? ' ('.$updatedBy.')' : '').'. Recarga la versión guardada o guarda tu versión como copia antes de continuar.');
    }

    public function render(Request $request): JsonResponse|false
    {
        return $request->expectsJson() || $request->isJson()
            ? response()->json(['message' => $this->getMessage(), 'current_revision' => $this->currentRevision, 'updated_by' => $this->updatedBy], 409)
            : false;
    }
}
