<?php

namespace App\Services\Auth;

use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialUser;

/**
 * Politica P1 del login social (ADR-004). Nunca crea usuarios. El email NO es la identidad: solo sirve para
 * la vinculacion automatica del primer login, y unicamente con Google verificado en ambos lados.
 *
 *  - identidad (proveedor, id) existente y no bloqueada  -> su usuario
 *  - identidad bloqueada (desvinculada)                  -> rechazo
 *  - Facebook sin identidad                              -> rechazo (solo se vincula a mano desde el perfil)
 *  - Google sin identidad: email_verified=true + cuenta local verificada -> vincula (social.linked auto)
 *  - cualquier otro caso (sin email, sin verificar, sin cuenta...)      -> rechazo
 *
 * Los motivos de rechazo (reason) son solo para la auditoria; al usuario se le muestra siempre el mismo mensaje.
 */
class SocialIdentityResolver
{
    public function __construct(private readonly AuditLog $audit) {}

    public function resolve(string $provider, SocialUser $social): SocialResolution
    {
        $providerId = trim((string) $social->getId());
        if ($providerId === '') {
            return SocialResolution::reject('no_id');
        }

        $identity = SocialIdentity::with('user')->where('provider', $provider)->where('provider_user_id', $providerId)->first();
        if ($identity !== null) {
            if ($identity->isBlocked()) {
                return SocialResolution::reject('blocked');
            }
            if ($identity->user === null) {
                return SocialResolution::reject('orphan');
            }

            $identity->forceFill(['last_login_at' => now()])->save();

            return SocialResolution::accept($identity->user, $identity);
        }

        // La vinculacion automatica solo existe para Google.
        if ($provider !== SocialIdentity::GOOGLE) {
            return SocialResolution::reject('unlinked');
        }

        return $this->autoLinkGoogle($social, $providerId);
    }

    public const LINKED = 'linked';

    public const RELINKED = 'relinked';

    public const ALREADY = 'already';

    public const TAKEN = 'taken';

    public const OTHER_IDENTITY = 'other_identity';

    public const NO_ID = 'no_id';

    /**
     * Vinculacion manual desde el perfil (sesion iniciada y contrasena confirmada; lo comprueba el controlador).
     * Si la identidad (proveedor, id) ya es de OTRO usuario no cambia nada. Si es de este usuario y estaba
     * desvinculada, la desbloquea. Un usuario tiene como mucho una identidad activa por proveedor.
     */
    public function link(User $user, string $provider, SocialUser $social): string
    {
        $providerId = trim((string) $social->getId());
        if ($providerId === '') {
            $this->audit->record($user, 'social.linked', AuditLog::DENIED, 'social_identity', null, 'provider='.$provider.';motivo=no_id', 'web');

            return self::NO_ID;
        }

        $identity = SocialIdentity::where('provider', $provider)->where('provider_user_id', $providerId)->first();
        if ($identity !== null) {
            if ($identity->user_id !== $user->id) {
                // Una identidad desvinculada (bloqueada) en otra cuenta se transfiere: quien la vincula ya confirmo
                // la contrasena y la sesion del proveedor es suya. Una activa nunca se mueve.
                if (! $identity->isBlocked()) {
                    $this->audit->record($user, 'social.linked', AuditLog::DENIED, 'social_identity', null, 'provider='.$provider.';motivo=taken', 'web');

                    return self::TAKEN;
                }

                return $this->transfer($user, $provider, $providerId, $identity, $social);
            }
            if (! $identity->isBlocked()) {
                return self::ALREADY;
            }
            if ($this->hasOtherActive($user, $provider, $identity->id)) {
                return $this->otherIdentity($user, $provider);
            }

            $identity->forceFill(['blocked_at' => null, 'linked_at' => now(), 'email' => $this->emailOf($social)])->save();
            $this->audit->record($user, 'social.linked', AuditLog::OK, 'social_identity', $identity->id, 'provider='.$provider.';modo=manual;desbloqueada', 'web');

            return self::RELINKED;
        }

        if ($this->hasOtherActive($user, $provider)) {
            return $this->otherIdentity($user, $provider);
        }

        try {
            $identity = $user->socialIdentities()->create([
                'provider' => $provider,
                'provider_user_id' => $providerId,
                'email' => $this->emailOf($social),
                'linked_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->audit->record($user, 'social.linked', AuditLog::DENIED, 'social_identity', null, 'provider='.$provider.';motivo=taken', 'web');

            return self::TAKEN;
        }

        $this->audit->record($user, 'social.linked', AuditLog::OK, 'social_identity', $identity->id, 'provider='.$provider.';modo=manual', 'web');

        return self::LINKED;
    }

    private function hasOtherActive(User $user, string $provider, ?int $exceptId = null): bool
    {
        return $user->socialIdentities()->where('provider', $provider)->whereNull('blocked_at')
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
    }

    private function otherIdentity(User $user, string $provider): string
    {
        $this->audit->record($user, 'social.linked', AuditLog::DENIED, 'social_identity', null, 'provider='.$provider.';motivo=other_identity', 'web');

        return self::OTHER_IDENTITY;
    }

    /** Mueve una identidad bloqueada de otra cuenta a esta: se borra la fila vieja y se crea la nueva. */
    private function transfer(User $user, string $provider, string $providerId, SocialIdentity $old, SocialUser $social): string
    {
        if ($this->hasOtherActive($user, $provider)) {
            return $this->otherIdentity($user, $provider);
        }

        try {
            $identity = DB::transaction(function () use ($user, $provider, $providerId, $old, $social) {
                $old->delete();

                return $user->socialIdentities()->create([
                    'provider' => $provider,
                    'provider_user_id' => $providerId,
                    'email' => $this->emailOf($social),
                    'linked_at' => now(),
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $this->audit->record($user, 'social.linked', AuditLog::DENIED, 'social_identity', null, 'provider='.$provider.';motivo=taken', 'web');

            return self::TAKEN;
        }

        $this->audit->record($user, 'social.linked', AuditLog::OK, 'social_identity', $identity->id, 'provider='.$provider.';modo=manual;transferida_de='.$old->user_id, 'web');

        return self::LINKED;
    }

    /** Desvincula: la fila queda bloqueada para que la vinculacion automatica no la vuelva a enlazar. */
    public function unlink(User $user, string $provider): bool
    {
        $identities = $user->socialIdentities()->where('provider', $provider)->whereNull('blocked_at')->get();
        if ($identities->isEmpty()) {
            return false;
        }

        foreach ($identities as $identity) {
            $identity->forceFill(['blocked_at' => now()])->save();
            $this->audit->record($user, 'social.unlinked', AuditLog::OK, 'social_identity', $identity->id, 'provider='.$provider, 'web');
        }

        return true;
    }

    private function emailOf(SocialUser $social): ?string
    {
        $email = trim((string) $social->getEmail());

        return $email === '' ? null : $email;
    }

    private function autoLinkGoogle(SocialUser $social, string $providerId): SocialResolution
    {
        $email = trim((string) $social->getEmail());
        if ($email === '') {
            return SocialResolution::reject('no_email');
        }
        if (! $this->googleEmailVerified($social)) {
            return SocialResolution::reject('provider_unverified');
        }

        $user = $this->userByExactEmail($email);
        if ($user === null) {
            return SocialResolution::reject('no_account');
        }
        if (! $user->hasVerifiedEmail()) {
            return SocialResolution::reject('local_unverified');
        }

        // Desvinculada antes, o ya con otra cuenta de Google: no se vuelve a enlazar sola.
        $previous = $user->socialIdentities()->where('provider', SocialIdentity::GOOGLE)->first();
        if ($previous !== null) {
            return SocialResolution::reject($previous->isBlocked() ? 'blocked_for_user' : 'other_identity');
        }

        try {
            $identity = $user->socialIdentities()->create([
                'provider' => SocialIdentity::GOOGLE,
                'provider_user_id' => $providerId,
                'email' => $email,
                'linked_at' => now(),
                'last_login_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return SocialResolution::reject('race');
        }

        $this->audit->record($user, 'social.linked', AuditLog::OK, 'social_identity', $identity->id, 'provider=google;modo=auto', 'web');

        return SocialResolution::accept($user, $identity, 'linked_auto');
    }

    /**
     * Comparacion exacta, sensible a bytes (CWE-176). La consulta usa el indice de `email` y, con una collation `_ci`
     * de MySQL, puede devolver filas de mas (`ss`=`ß`, `a`=`ä`); el filtro definitivo es en PHP, con el email en
     * minusculas identico byte a byte. Un email de Google no ASCII solo vincula con una cuenta igual de identica.
     */
    private function userByExactEmail(string $email): ?User
    {
        $candidates = User::where('email', $email)->orWhere('email', mb_strtolower($email))->limit(20)->get();

        return self::pickExactEmail($candidates, $email);
    }

    /**
     * Filtro definitivo de {@see userByExactEmail()}: de las filas que devuelve la consulta (que con una collation
     * `_ci` pueden incluir `ß` para `ss`), solo la que tiene el email en minusculas identico byte a byte.
     *
     * @param  iterable<User>  $candidates
     */
    public static function pickExactEmail(iterable $candidates, string $email): ?User
    {
        $wanted = mb_strtolower($email);
        foreach ($candidates as $user) {
            if (hash_equals(mb_strtolower((string) $user->email), $wanted)) {
                return $user;
            }
        }

        return null;
    }

    /** Falla cerrado: solo el booleano true (o la cadena "true") del userinfo de Google cuenta como verificado. */
    private function googleEmailVerified(SocialUser $social): bool
    {
        $raw = is_array($social->user ?? null) ? $social->user : [];
        $value = $raw['email_verified'] ?? null;

        return $value === true || $value === 'true';
    }
}
