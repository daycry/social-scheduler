<?php

namespace App\Support;

use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Cifra el historial de Inertia de la respuesta actual (CWE-525): las props con un secreto (semilla 2FA, API key o enlace .ics recién
 * creados) no deben poder leerse con «Atrás» tras cerrar sesión. El cifrado usa crypto.subtle, que solo existe en contexto seguro: en http
 * (salvo localhost) Inertia no podría escribir el historial y la página se quedaría colgada, así que allí no se activa.
 */
final class EncryptedHistory
{
    public static function enable(Request $request): void
    {
        if ($request->secure() || in_array($request->getHost(), ['localhost', '127.0.0.1', '[::1]'], true)) {
            Inertia::encryptHistory();
        }
    }
}
