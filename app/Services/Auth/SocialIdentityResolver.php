<?php

namespace App\Services\Auth;

use App\Models\SocialIdentity;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Database\UniqueConstraintViolationException;
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

    private function autoLinkGoogle(SocialUser $social, string $providerId): SocialResolution
    {
        $email = trim((string) $social->getEmail());
        if ($email === '') {
            return SocialResolution::reject('no_email');
        }
        if (! $this->googleEmailVerified($social)) {
            return SocialResolution::reject('provider_unverified');
        }

        $user = User::whereRaw('lower(email) = ?', [mb_strtolower($email)])->first();
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

    /** Falla cerrado: solo el booleano true (o la cadena "true") del userinfo de Google cuenta como verificado. */
    private function googleEmailVerified(SocialUser $social): bool
    {
        $raw = is_array($social->user ?? null) ? $social->user : [];
        $value = $raw['email_verified'] ?? null;

        return $value === true || $value === 'true';
    }
}
