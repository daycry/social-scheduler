<?php

namespace App\Services\Push;

/** El estado público nunca incluye la clave privada ni los datos de suscripción. */
final class PushConfiguration
{
    public function enabled(): bool
    {
        if (! (bool) config('push.enabled', true)) {
            return false;
        }
        $subject = config('push.vapid.subject');
        if (! is_string($subject) || str_contains($subject, "\n") || str_contains($subject, "\r")
            || ! ((str_starts_with($subject, 'mailto:') && filter_var(substr($subject, 7), FILTER_VALIDATE_EMAIL))
                || (str_starts_with($subject, 'https://') && filter_var($subject, FILTER_VALIDATE_URL)))) {
            return false;
        }
        $public = $this->decode(config('push.vapid.public_key'), 65);
        $private = $this->decode(config('push.vapid.private_key'), 32);
        if ($public === null || $private === null || $public[0] !== "\x04") {
            return false;
        }
        // SEC1 sin punto público: OpenSSL lo deriva del escalar y permite comprobar que las dos claves corresponden.
        $der = hex2bin('30310201010420').$private.hex2bin('a00a06082a8648ce3d030107');
        $pem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
        $key = @openssl_pkey_get_private($pem);
        $ec = $key === false ? null : (openssl_pkey_get_details($key)['ec'] ?? null);

        return $ec !== null && hash_equals($public, "\x04".str_pad($ec['x'], 32, "\x00", STR_PAD_LEFT).str_pad($ec['y'], 32, "\x00", STR_PAD_LEFT));
    }

    private function decode(mixed $value, int $length): ?string
    {
        if (! is_string($value) || ! preg_match('/\A[A-Za-z0-9_-]+\z/D', $value)) {
            return null;
        }
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);

        return $bytes !== false && strlen($bytes) === $length
            && rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=') === $value ? $bytes : null;
    }

    public function publicState(): array
    {
        $enabled = $this->enabled();

        return [
            'enabled' => $enabled,
            'public_key' => $enabled ? config('push.vapid.public_key') : null,
            'unavailable_reason' => $enabled ? null : 'Las notificaciones push no están configuradas en este servidor.',
        ];
    }
}
