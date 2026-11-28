<?php

namespace App\Services\Push;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Media\UnsafeUrlException;
use App\Services\Media\UrlGuard;
use App\Support\Ownership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PushSubscriptions
{
    public function __construct(private readonly UrlGuard $guard, private readonly PushConfiguration $configuration) {}

    /** @param array<string, mixed> $data */
    public function subscribe(User $user, array $data): PushSubscription
    {
        abort_unless($this->configuration->enabled(), 409, 'Las notificaciones push no están configuradas en este servidor.');
        $valid = Validator::make($data, [
            'endpoint' => ['required', 'string', 'max:2048'],
            'device_name' => ['required', 'string', 'max:120', 'not_regex:/[\p{C}]/u'],
            'keys' => ['required', 'array:p256dh,auth'],
            'keys.p256dh' => ['required', 'string', 'size:87', 'regex:/\A[A-Za-z0-9_-]+\z/D'],
            'keys.auth' => ['required', 'string', 'size:22', 'regex:/\A[A-Za-z0-9_-]+\z/D'],
        ])->validate();
        $point = base64_decode(strtr($valid['keys']['p256dh'], '-_', '+/'), true);
        $auth = base64_decode(strtr($valid['keys']['auth'], '-_', '+/'), true);
        $canonical = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        if ($point === false || strlen($point) !== 65 || $point[0] !== "\x04" || $canonical($point) !== $valid['keys']['p256dh']
            || $auth === false || strlen($auth) !== 16 || $canonical($auth) !== $valid['keys']['auth']) {
            throw ValidationException::withMessages(['keys' => 'La suscripción del navegador no es válida.']);
        }
        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$point;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
        if (@openssl_pkey_get_public($pem) === false) {
            throw ValidationException::withMessages(['keys.p256dh' => 'La clave del navegador no es válida.']);
        }
        try {
            $url = $this->guard->validate($valid['endpoint']);
            if ($url->scheme !== 'https') {
                throw new UnsafeUrlException('El receptor push debe usar HTTPS.');
            }
        } catch (UnsafeUrlException $error) {
            throw ValidationException::withMessages(['endpoint' => $error->getMessage()]);
        }

        // Los gap locks del índice único pueden cruzarse entre propietarios. No hay HTTP en esta transacción.
        return DB::transaction(function () use ($user, $valid, $url): PushSubscription {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $subscription = PushSubscription::withoutUserScope()->where('endpoint_hash', hash('sha256', $url->url))->lockForUpdate()->first();
            if ($subscription !== null) {
                if ((int) $subscription->user_id !== (int) $user->id) {
                    throw ValidationException::withMessages(['endpoint' => 'No se pudo vincular este navegador. Restablece el permiso de notificaciones en sus ajustes y vuelve a activarlo.']);
                }
            } else {
                if (PushSubscription::ownedBy($user->id)->count() >= max(1, (int) config('push.max_devices_per_user', 20))) {
                    throw ValidationException::withMessages(['device_name' => 'Has alcanzado el límite de dispositivos: revoca uno antes de añadir otro.']);
                }
                $subscription = new PushSubscription;
                $subscription->user_id = $user->id;
            }
            $subscription->forceFill([
                'endpoint_hash' => hash('sha256', $url->url), 'endpoint' => $url->url,
                'keys' => ['p256dh' => $valid['keys']['p256dh'], 'auth' => $valid['keys']['auth']],
                'device_name' => trim($valid['device_name']), 'last_seen_at' => now(),
            ])->save();

            return $subscription->fresh();
        }, 3);
    }

    public function revoke(User $user, PushSubscription $subscription): void
    {
        Ownership::assert($subscription, $user);
        $subscription->delete();
    }
}
