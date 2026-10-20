<?php

namespace App\Http\Middleware;

use App\Connectors\Network;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Services\InboxService;
use App\Support\CurrentBrand;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Aditivo (shell): `shell` trae las marcas del usuario, la marca actual y sus cuentas por red. Es lazy
     * (solo se evalua si hay sesion) y hace como mucho 2 consultas, sin N+1.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            // Contador de avisos sin leer para la barra de navegacion.
            'unreadNotifications' => fn () => $request->user() ? $request->user()->unreadNotifications()->count() : 0,
            // Comentarios sin leer del Inbox (una sola consulta COUNT, lazy y solo con sesion).
            'unreadInbox' => fn () => $request->user() ? app(InboxService::class)->unreadCount($request->user()) : 0,
            // Registro publico abierto: oculta los enlaces «Crear cuenta» cuando REGISTRATION_ENABLED=false.
            'registrationEnabled' => (bool) config('app.registration_enabled', true),
            'shell' => fn () => $request->user() ? $this->shell($request) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function shell(Request $request): array
    {
        $brands = CurrentBrand::brands($request);
        $current = CurrentBrand::resolve($request);

        // Avatares de las marcas (URL temporal); si el disco no puede firmarlas, el selector muestra la inicial.
        $avatars = [];
        $withAvatar = $brands->filter(fn ($b) => $b->avatar_media_id);
        if ($withAvatar->isNotEmpty()) {
            MediaAsset::query()->whereIn('id', $withAvatar->pluck('avatar_media_id'))->get()->each(function (MediaAsset $m) use ($withAvatar, &$avatars): void {
                try {
                    $url = $m->publicUrl(60);
                } catch (\Throwable) {
                    return;
                }
                foreach ($withAvatar->where('avatar_media_id', $m->id) as $b) {
                    $avatars[$b->id] = $url;
                }
            });
        }

        $accounts = [];
        if ($current) {
            // Una consulta; sin tokens (lista explicita de columnas).
            SocialAccount::query()
                ->where('brand_id', $current->id)
                ->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)
                ->orderBy('id')
                ->get(['id', 'brand_id', 'network', 'name', 'username', 'status'])
                ->each(function (SocialAccount $a) use (&$accounts): void {
                    $accounts[$a->network->value][] = [
                        'id' => $a->id, 'name' => $a->name, 'username' => $a->username, 'status' => $a->status,
                    ];
                });
        }

        return [
            'brands' => $brands->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'avatar_url' => $avatars[$b->id] ?? null])->values()->all(),
            'currentBrand' => $current ? ['id' => $current->id, 'name' => $current->name, 'avatar_url' => $avatars[$current->id] ?? null] : null,
            'accounts' => (object) $accounts,
            'networks' => collect(Network::cases())->map(fn (Network $n) => [
                'value' => $n->value, 'label' => $n->label(), 'provider' => $n->provider(),
            ])->values()->all(),
        ];
    }
}
