<?php

namespace App\Services\Sites;

use App\Exceptions\PostValidationException;
use App\Models\Site;
use App\Models\SiteToken;
use App\Models\User;
use App\Services\SmartLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Control de acceso de los sitios publicados (T-15). Sin sesion de Laravel: al acertar la contrasena o abrir un enlace con token
 * se emite una cookie propia FIRMADA (HMAC con la clave de la app) que lleva `site_id`, la version de acceso, el token y la caducidad.
 * Cambiar el modo/contrasena o revocar sube `access_version`; revocar un enlace se comprueba en CADA peticion: el efecto es inmediato.
 * Nada identifica al visitante: los limitadores usan un hash (clave de la app) de IP + sitio, y no se guarda en base de datos.
 */
class SiteAccess
{
    public const SESSION_MINUTES = 720;

    public const PASSWORD_ATTEMPTS = 5;

    public const PASSWORD_DECAY = 300;

    public const GLOBAL_ATTEMPTS = 50;

    public const GLOBAL_DECAY = 900;

    public const MAX_TOKENS = 20;

    /** ¿Puede ver el sitio quien hace esta peticion? (publico siempre; el resto exige una cookie vigente). */
    public function allows(Request $request, Site $site): bool
    {
        if ($site->access_mode === Site::ACCESS_PUBLIC) {
            return true;
        }
        $raw = (string) $request->cookies->get(self::cookieName($site), '');
        if (preg_match('/^(\d+)\.(\d+)\.([a-f0-9]{64})$/', $raw, $m) !== 1) {
            return false;
        }
        [, $tokenId, $exp, $sig] = $m;
        if ((int) $exp < time() || ! hash_equals($this->sign($site, (int) $tokenId, (int) $exp), $sig)) {
            return false;
        }
        if ($site->access_mode === Site::ACCESS_TOKEN) {
            $token = $tokenId > 0 ? SiteToken::query()->where('site_id', $site->id)->find((int) $tokenId) : null;

            return $token !== null && $token->isUsable();
        }

        return (int) $tokenId === 0;
    }

    /** Cookie de acceso (`$tokenId` = 0 si es por contrasena). */
    public function grant(Request $request, Site $site, int $tokenId = 0): Cookie
    {
        $exp = time() + self::SESSION_MINUTES * 60;

        return new Cookie(self::cookieName($site), $tokenId.'.'.$exp.'.'.$this->sign($site, $tokenId, $exp), $exp, '/s/'.$site->publicSlug(), null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }

    public function forget(Request $request, Site $site): Cookie
    {
        return new Cookie(self::cookieName($site), '', 1, '/s/'.$site->publicSlug(), null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX);
    }

    public function checkPassword(Site $site, string $password): bool
    {
        return $site->password_hash !== null && Hash::check($password, $site->password_hash);
    }

    /** Huella del cliente para limitadores: IPv4 completa; IPv6 por prefijo /64 (un cliente controla todo su /64). */
    public static function clientNet(Request $request): string
    {
        $ip = (string) $request->ip();
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16) {
            return bin2hex(substr($bin, 0, 8)).'::/64';
        }

        return $ip;
    }

    /** Clave de limitador sin IP en claro (HMAC de red + sitio). */
    public function limiterKey(Request $request, Site $site, string $scope): string
    {
        return 'site-'.$scope.':'.hash_hmac('sha256', $site->id.'|'.self::clientNet($request), (string) config('app.key'));
    }

    /**
     * Reserva un intento ANTES de verificar (el contador sube de forma atomica, asi que las peticiones en paralelo no se cuelan):
     * por cliente 5 cada 5 min y, ademas, tope global del sitio de 50 FALLOS cada 15 min.
     */
    public function reserveAttempt(Request $request, Site $site): bool
    {
        if (RateLimiter::tooManyAttempts('site-pw-all:'.$site->id, self::GLOBAL_ATTEMPTS)) {
            return false;
        }

        return RateLimiter::hit($this->limiterKey($request, $site, 'pw'), self::PASSWORD_DECAY) <= self::PASSWORD_ATTEMPTS;
    }

    public function failedAttempt(Site $site): void
    {
        RateLimiter::hit('site-pw-all:'.$site->id, self::GLOBAL_DECAY);
    }

    public function clearPassword(Request $request, Site $site): void
    {
        RateLimiter::clear($this->limiterKey($request, $site, 'pw'));
    }

    /** Un token de enlace abre el sitio: se marca su ultimo uso. Comparte el limite de intentos con la contrasena. */
    public function openWithToken(Request $request, Site $site, string $plain): ?int
    {
        if (! $this->reserveAttempt($request, $site)) {
            return null;
        }
        $token = $this->findToken($site, $plain);
        if (! $token || ! $token->isUsable()) {
            $this->failedAttempt($site);

            return null;
        }
        $this->clearPassword($request, $site);
        $token->forceFill(['last_used_at' => now()])->save();

        return $token->id;
    }

    // ---- Gestion del acceso por el propietario ---------------------------------------------------------------------------

    /** Modo de acceso, contrasena (hash) y noindex. Cambiar el modo o la contrasena cierra todas las sesiones de acceso emitidas. */
    public function updateAccess(User $user, Site $site, string $mode, ?string $password, bool $noindex): Site
    {
        abort_unless($site->user_id === $user->id, 404);
        if (! in_array($mode, Site::ACCESS_MODES, true)) {
            throw new PostValidationException(['access_mode' => ['Modo de acceso no válido.']]);
        }
        if ($password !== null && $password !== '' && (mb_strlen($password) < 8 || mb_strlen($password) > 128)) {
            throw new PostValidationException(['password' => ['La contraseña debe tener entre 8 y 128 caracteres.']]);
        }
        $hasPassword = $site->password_hash || ($password !== null && $password !== '');
        if ($mode === Site::ACCESS_PASSWORD && ! $hasPassword) {
            throw new PostValidationException(['password' => ['Define una contraseña para este modo de acceso.']]);
        }

        $changed = $mode !== $site->access_mode || ($password !== null && $password !== '');
        $site->forceFill([
            'access_mode' => $mode, 'noindex' => $noindex,
            'password_hash' => $password !== null && $password !== '' ? Hash::make($password) : $site->password_hash,
            'access_version' => $site->access_version + ($changed ? 1 : 0),
        ])->save();

        return $site;
    }

    /** @return array{0: SiteToken, 1: string} el texto claro solo existe en esta respuesta. */
    public function createToken(User $user, Site $site, string $label, ?int $days): array
    {
        abort_unless($site->user_id === $user->id, 404);
        $label = SmartLinkService::clean($label, 60);
        if ($label === '') {
            throw new PostValidationException(['label' => ['Pon un nombre al enlace.']]);
        }
        if ($days !== null && ($days < 1 || $days > 365)) {
            throw new PostValidationException(['days' => ['La caducidad va de 1 a 365 días.']]);
        }
        if (SiteToken::query()->where('site_id', $site->id)->whereNull('revoked_at')->count() >= self::MAX_TOKENS) {
            throw new PostValidationException(['label' => ['Máximo '.self::MAX_TOKENS.' enlaces activos. Revoca alguno.']]);
        }
        $plain = 'st_'.Str::random(40);
        $token = SiteToken::create(['site_id' => $site->id, 'label' => $label, 'token_hash' => hash('sha256', $plain), 'expires_at' => $days ? now()->addDays($days) : null]);

        return [$token, $plain];
    }

    /** Revoca un enlace: sus sesiones dejan de valer en la siguiente peticion (la sesion comprueba el token). */
    public function revokeToken(User $user, Site $site, SiteToken $token): void
    {
        abort_unless($site->user_id === $user->id, 404);
        abort_unless($token->site_id === $site->id, 404);
        $token->forceFill(['revoked_at' => $token->revoked_at ?? now()])->save();
    }

    /** Cierra todo acceso concedido (sesiones por contrasena y por enlace) y revoca todos los enlaces. */
    public function revokeAllAccess(User $user, Site $site): void
    {
        abort_unless($site->user_id === $user->id, 404);
        DB::transaction(function () use ($site) {
            SiteToken::query()->where('site_id', $site->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $site->forceFill(['access_version' => $site->access_version + 1])->save();
        });
    }

    public function findToken(Site $site, string $plain): ?SiteToken
    {
        if ($plain === '' || strlen($plain) > 100) {
            return null;
        }

        return SiteToken::query()->where('site_id', $site->id)->where('token_hash', hash('sha256', $plain))->first();
    }

    private static function cookieName(Site $site): string
    {
        return 'site_access_'.$site->id;
    }

    private function sign(Site $site, int $tokenId, int $exp): string
    {
        return hash_hmac('sha256', implode('|', ['site', $site->id, $site->access_version, $site->access_mode, $tokenId, $exp]), (string) config('app.key'));
    }
}
