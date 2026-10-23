<?php

namespace App\Services\Auth\Social;

use Illuminate\Support\Facades\Date;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;
use RuntimeException;

/**
 * Proveedor de Socialite FALSO para la bateria E2E (T-23). Google y Facebook reales no se pueden automatizar.
 *
 * Sustituye a los drivers `google-login` y `facebook-login` SOLO con `APP_ENV=e2e` y un APP_URL local
 * (`register()`); en cualquier otro entorno ni se instancia. Nunca se activa por peticion: la identidad que
 * "devuelve el proveedor" la deja la bateria en un fichero del servidor (`identityFile()`), no en la query.
 *
 * El viaje conserva el `state` real de Socialite (se valida igual que con el proveedor de verdad): `redirect()` vuelve
 * directo al callback de la app con `code` y `state`, o con `error=access_denied` si la bateria simula una cancelacion.
 */
class FakeSocialiteProvider extends AbstractProvider implements ProviderInterface
{
    /** Hosts de APP_URL en los que se admite el driver falso (segunda barrera tras APP_ENV=e2e). */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /** Registra los drivers falsos si procede; devuelve si lo hizo. */
    public static function register(): bool
    {
        if (! self::allowed((string) app()->environment(), (string) config('app.url'))) {
            return false;
        }

        foreach (['google', 'facebook'] as $provider) {
            Socialite::extend($provider.'-login', fn () => Socialite::buildProvider(FakeSocialiteProvider::class, (array) config('services.'.$provider.'_login')));
        }

        return true;
    }

    /** Solo `e2e` y solo con un APP_URL local: un `APP_ENV=e2e` olvidado en un servidor publico no abre el login. */
    public static function allowed(string $environment, string $appUrl): bool
    {
        return $environment === 'e2e' && in_array(strtolower((string) parse_url($appUrl, PHP_URL_HOST)), self::LOCAL_HOSTS, true);
    }

    public static function identityFile(): string
    {
        return storage_path('framework/cache/e2e-social.json');
    }

    /** @return array<string, mixed>|null identidad que la bateria dejo para este proveedor (id, email, email_verified, name, error) */
    private function identity(): ?array
    {
        $all = is_file(self::identityFile()) ? json_decode((string) file_get_contents(self::identityFile()), true) : null;
        $identity = is_array($all) ? ($all[$this->providerName()] ?? null) : null;

        return is_array($identity) ? $identity : null;
    }

    private function providerName(): string
    {
        return str_contains((string) $this->redirectUrl, '/auth/facebook/') ? 'facebook' : 'google';
    }

    protected function getAuthUrl($state)
    {
        $query = ($this->identity()['error'] ?? null) !== null
            ? ['error' => (string) $this->identity()['error'], 'state' => $state]
            : ['code' => 'fake-'.Date::now()->timestamp, 'state' => $state];

        return $this->redirectUrl.'?'.http_build_query($query);
    }

    protected function getTokenUrl()
    {
        return '';
    }

    public function getAccessTokenResponse($code)
    {
        return ['access_token' => 'fake-token'];
    }

    protected function getUserByToken($token)
    {
        $identity = $this->identity() ?? throw new RuntimeException('Sin identidad falsa preparada para este proveedor.');

        $raw = ['id' => (string) $identity['id'], 'sub' => (string) $identity['id'], 'name' => $identity['name'] ?? 'Persona E2E'];
        if (($identity['email'] ?? null) !== null) {
            $raw['email'] = (string) $identity['email'];
        }
        // Solo Google informa email_verified; con `null` el campo no existe (como en Facebook).
        if (array_key_exists('email_verified', $identity) && $identity['email_verified'] !== null) {
            $raw['email_verified'] = $identity['email_verified'];
        }

        return $raw;
    }

    protected function mapUserToObject(array $user)
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['id'],
            'nickname' => null,
            'name' => $user['name'] ?? null,
            'email' => $user['email'] ?? null,
            'avatar' => null,
        ]);
    }
}
