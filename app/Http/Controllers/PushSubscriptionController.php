<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\Push\PushConfiguration;
use App\Services\Push\PushSubscriptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Cookie as HttpCookie;

final class PushSubscriptionController extends Controller
{
    public function index(Request $request, PushConfiguration $configuration): Response
    {
        $devices = PushSubscription::ownedBy($request->user()->id)->orderByDesc('last_seen_at')->orderByDesc('id')
            ->get(['id', 'device_name', 'last_seen_at', 'last_sent_at'])->map(fn (PushSubscription $subscription) => [
                'id' => $subscription->id, 'device_name' => $subscription->device_name,
                'last_seen_at' => $subscription->last_seen_at->toIso8601String(),
                'last_sent_at' => $subscription->last_sent_at?->toIso8601String(),
                'current' => (string) $request->cookie('push_device') === (string) $subscription->id,
            ]);
        $current = $devices->firstWhere('current', true);
        if ($current !== null) {
            Cookie::queue($this->deviceCookie($request, (int) $current['id']));
        }

        return Inertia::render('Profile/Notifications', [
            'push' => $configuration->publicState(),
            'subscriptions' => $devices->all(),
        ]);
    }

    public function store(Request $request, PushSubscriptions $subscriptions): RedirectResponse
    {
        $data = $request->all();
        foreach (['keys', 'endpoint'] as $field) {
            $request->request->remove($field);
            $request->query->remove($field);
            if ($request->isJson()) {
                $request->json()->remove($field);
            }
        }
        $subscription = $subscriptions->subscribe($request->user(), $data);

        return redirect()->route('profile.notifications')->with('success', 'Dispositivo suscrito.')
            ->withCookie($this->deviceCookie($request, (int) $subscription->id));
    }

    public function destroy(Request $request, PushSubscription $pushSubscription, PushSubscriptions $subscriptions): RedirectResponse
    {
        $subscriptions->revoke($request->user(), $pushSubscription);

        $response = redirect()->route('profile.notifications')->with('success', 'Suscripción revocada.');
        if ((string) $request->cookie('push_device') === (string) $pushSubscription->id) {
            $response->withoutCookie('push_device', '/');
        }

        return $response;
    }

    private function deviceCookie(Request $request, int $id): HttpCookie
    {
        return Cookie::forever('push_device', (string) $id, '/', null, $request->isSecure(), true, false, 'lax');
    }
}
