<?php

namespace App\Listeners;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Throwable;

/** La cookie cifrada identifica este navegador; nunca revoca dispositivos de otra cuenta. */
final class RevokePushOnLogout
{
    public function handle(Logout $event): void
    {
        $device = request()->cookie('push_device');
        try {
            if ($event->user instanceof User && is_string($device) && ctype_digit($device)) {
                PushSubscription::ownedBy($event->user->id)->whereKey($device)->delete();
            }
        } catch (Throwable $error) {
            // Una caída del almacén no debe impedir cerrar la sesión. El push solo muestra avisos genéricos.
            Log::warning('No se pudo revocar el dispositivo push al cerrar sesión.', ['exception_class' => $error::class]);
        } finally {
            Cookie::queue(Cookie::forget('push_device', '/'));
        }
    }
}
