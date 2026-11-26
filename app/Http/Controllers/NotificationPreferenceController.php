<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;

/**
 * Preferencias de avisos por tipo (T-86): resumen semanal (y su parrafo de IA) y recordatorio previo de publicacion manual. El correo global
 * (`notify_by_mail`) sigue en el perfil. La baja del resumen desde el correo es un enlace firmado de un clic, igual que la de los informes
 * programados: el GET solo confirma (sin efectos, para que escaneres y prefetchers de correo no den de baja a nadie) y el POST firmado
 * (RFC 8058, fuera de CSRF en `bootstrap/app.php`) desactiva el resumen de ESE usuario. Idempotente.
 */
class NotificationPreferenceController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $request->user()->update($request->validate([
            'notify_weekly_digest' => ['required', 'boolean'],
            'weekly_digest_ai' => ['required', 'boolean'],
            'notify_manual_reminder' => ['required', 'boolean'],
        ]));

        return to_route('profile.notifications');
    }

    public function confirmUnsubscribe(Request $request, int $user): HttpResponse
    {
        return response()->view('unsubscribe', self::page(false, $request->fullUrl()), 200, ['X-Robots-Tag' => 'noindex']);
    }

    public function unsubscribe(int $user): HttpResponse
    {
        User::query()->whereKey($user)->update(['notify_weekly_digest' => false]);

        return response()->view('unsubscribe', self::page(true), 200, ['X-Robots-Tag' => 'noindex']);
    }

    /** Textos de la pagina de baja comun (`resources/views/unsubscribe.blade.php`, la misma de los informes programados). */
    private static function page(bool $done, string $action = ''): array
    {
        return [
            'done' => $done, 'action' => $action, 'title' => 'Baja del resumen semanal', 'question' => '¿Dejar de recibir el resumen semanal?',
            'detail' => 'Se desactivará el resumen semanal de tu cuenta, tanto el aviso en la aplicación como el correo.',
            'doneText' => 'Ya no recibirás el resumen semanal. Puedes volver a activarlo cuando quieras en las preferencias de avisos.',
        ];
    }
}
