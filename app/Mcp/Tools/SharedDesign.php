<?php

namespace App\Mcp\Tools;

use App\Models\Studio\Design;
use App\Models\User;
use App\Services\Studio\StudioCollab;

/** Diseño propio o compartido con el usuario de la API key; el rol lo aplica después el servicio (404 sin acceso, 403 sin permiso). */
trait SharedDesign
{
    protected function accessibleDesign(User $user, mixed $id): Design
    {
        $id = is_numeric($id) ? (int) $id : null;
        $design = $id === null ? null : Design::withoutUserScope()->find($id);
        if ($design === null || app(StudioCollab::class)->role($user, $design) === null) {
            throw new NotOwned('Diseño no encontrado.', 'design', $id, $design !== null);
        }

        return $design;
    }
}
