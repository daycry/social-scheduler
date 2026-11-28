<?php

namespace App\Http\Controllers;

use App\Services\CalendarFeed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feed .ics del calendario (T-90). `show` es público (lo consulta la aplicación de calendario del usuario, sin sesión): el token de la URL
 * identifica al dueño, un token desconocido, revocado o regenerado responde 404 sin pista y la respuesta no se cachea ni se indexa.
 * `store` genera o regenera el token (el anterior deja de valer) y `destroy` lo revoca; ambos actúan siempre sobre el usuario autenticado.
 * La URL con el token se entrega UNA vez, cifrada en el flash (la base de datos solo guarda su hash).
 */
class CalendarFeedController extends Controller
{
    public function __construct(private readonly CalendarFeed $feed) {}

    public function show(string $token): Response
    {
        $user = $this->feed->userFor($token);
        abort_if($user === null, 404);

        return response($this->feed->render($user), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="calendario.ics"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $regenerated = $request->user()->calendar_feed_token_hash !== null;
        $url = $this->feed->issue($request->user());

        return back()
            ->with('calendar_feed_url', Crypt::encryptString($url))
            ->with('success', $regenerated ? 'Enlace regenerado: el anterior ya no funciona.' : 'Enlace de suscripción creado.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->feed->revoke($request->user());

        return back()->with('success', 'Enlace de suscripción revocado.');
    }
}
