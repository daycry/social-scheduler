<?php

namespace App\Services\TwoFactor;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) y codigos de recuperacion. Nunca escribe secretos, codigos TOTP ni codigos de
 * recuperacion en logs. El antirreplay vive en users.two_factor_last_used_at (ultimo paso aceptado),
 * no en cache: sobrevive a limpiezas y es atomico entre peticiones concurrentes.
 */
class TotpService
{
    public const RECOVERY_CODES = 8;

    /** Ventana de tolerancia de reloj: +-1 paso de 30 s. */
    private const WINDOW = 1;

    public function __construct(private readonly Google2FA $google2fa) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    /** URI otpauth:// para apps de autenticacion. */
    public function otpAuthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('app.name', 'Metricool'), $user->email, $secret);
    }

    /** QR en SVG generado en el servidor (el secreto no sale a servicios externos). */
    public function qrSvg(User $user, string $secret): string
    {
        $renderer = new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd);

        return trim((new Writer($renderer))->writeString($this->otpAuthUrl($user, $secret)));
    }

    /**
     * Verifica un TOTP del secreto del usuario (confirmado o pendiente). Acepta la ventana actual +-1 y
     * rechaza un paso ya usado (o anterior). El paso se reclama con un UPDATE condicional atomico.
     */
    public function verify(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($secret === null || ! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $last = (int) ($user->two_factor_last_used_at ?? 0); // 0 (no null): con null la libreria devuelve true en vez del paso
        $step = $this->google2fa->verifyKeyNewer($secret, $code, $last, self::WINDOW);

        if (! is_int($step)) {
            return false;
        }

        $claimed = DB::table('users')
            ->where('id', $user->id)
            ->where(fn ($q) => $q->whereNull('two_factor_last_used_at')->orWhere('two_factor_last_used_at', '<', $step))
            ->update(['two_factor_last_used_at' => $step]);

        if ($claimed === 0) {
            return false;
        }

        $user->forceFill(['two_factor_last_used_at' => $step])->syncOriginal();

        return true;
    }

    /**
     * Genera 8 codigos nuevos, guarda solo sus hashes (sustituyendo los anteriores) y devuelve el texto
     * en claro, que no se puede volver a obtener.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(User $user): array
    {
        $plain = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $plain[] = Str::lower(Str::random(5)).'-'.Str::lower(Str::random(5));
        }

        $user->forceFill(['two_factor_recovery_codes' => array_map(fn (string $c) => Hash::make($c), $plain)])->save();

        return $plain;
    }

    /** Cuantos codigos de recuperacion quedan sin usar. */
    public function remainingRecoveryCodes(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    /**
     * Consume un codigo de recuperacion (un solo uso). Devuelve cuantos quedan, o null si no es valido.
     */
    public function consumeRecoveryCode(User $user, string $code): ?int
    {
        $code = Str::lower(trim($code));
        if ($code === '') {
            return null;
        }

        return DB::transaction(function () use ($user, $code): ?int {
            $fresh = User::query()->whereKey($user->id)->lockForUpdate()->first();
            $hashes = $fresh?->two_factor_recovery_codes ?? [];

            foreach ($hashes as $i => $hash) {
                if (Hash::check($code, $hash)) {
                    unset($hashes[$i]);
                    $hashes = array_values($hashes);
                    $fresh->forceFill(['two_factor_recovery_codes' => $hashes])->save();
                    $user->forceFill(['two_factor_recovery_codes' => $hashes])->syncOriginal();

                    return count($hashes);
                }
            }

            return null;
        });
    }
}
