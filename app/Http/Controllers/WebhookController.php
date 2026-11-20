<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Services\Webhooks\WebhookSubscriptions;
use App\Support\CursorPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class WebhookController extends Controller
{
    public function __construct(private readonly WebhookSubscriptions $subscriptions) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate(['brand_id' => ['nullable', 'integer', 'min:1']] + CursorPage::RULES);
        $user = $request->user();
        $brands = Brand::ownedBy($user->id)->orderBy('name')->orderBy('id')->get(['id', 'name']);
        $brand = isset($filters['brand_id']) ? $brands->firstWhere('id', (int) $filters['brand_id']) : $brands->first();
        abort_if(isset($filters['brand_id']) && $brand === null, 404);
        $query = Webhook::ownedBy($user->id)->where('brand_id', $brand?->id)
            ->with(['latestDelivery' => fn ($query) => $query->where('webhook_deliveries.user_id', $user->id)])
            ->orderByDesc('created_at')->orderByDesc('id');
        [$hooks, $pagination] = CursorPage::fetch($query, $filters['limit'] ?? null, $filters['cursor'] ?? null,
            'webhooks:u'.$user->id.':b'.($brand?->id ?? 'none'), withTotal: true);

        return Inertia::render('Webhooks/Index', [
            'brands' => $brands, 'brand' => $brand,
            'webhooks' => $hooks->map(function (Webhook $hook): array {
                $last = $hook->latestDelivery;

                return [
                    'id' => $hook->id, 'name' => $hook->name, 'url' => $hook->url,
                    'events' => $hook->events, 'enabled' => $hook->enabled,
                    'last_delivery' => $last === null ? null : [
                        'status' => $last->status, 'attempts' => $last->attempts, 'http_status' => $last->last_http_status,
                        'reason' => $last->last_error === null ? null : (WebhookDelivery::ERRORS[$last->last_error] ?? 'Error de entrega.'),
                        'next_attempt_at' => $last->next_attempt_at?->toIso8601String(),
                        'updated_at' => $last->updated_at->toIso8601String(),
                    ],
                ];
            })->values(),
            'events' => Webhook::EVENTS, 'delivery_states' => WebhookDelivery::STATES,
            'max_per_user' => WebhookSubscriptions::limit(),
            'pagination' => $pagination + $filters,
        ]);
    }

    public function store(Request $request, Brand $brand): RedirectResponse
    {
        $this->subscriptions->create($request->user(), $brand, $this->inputWithoutSecretFlash($request));

        return redirect()->route('webhooks.index', ['brand_id' => $brand->id])->with('success', 'Webhook creado.');
    }

    public function update(Request $request, Webhook $webhook): RedirectResponse
    {
        $this->subscriptions->update($request->user(), $webhook, $this->inputWithoutSecretFlash($request));

        return redirect()->route('webhooks.index', ['brand_id' => $webhook->brand_id])->with('success', 'Webhook actualizado.');
    }

    public function destroy(Request $request, Webhook $webhook): RedirectResponse
    {
        $this->subscriptions->delete($request->user(), $webhook);

        return redirect()->route('webhooks.index', ['brand_id' => $webhook->brand_id])->with('success', 'Webhook eliminado.');
    }

    /** El validador recibe el secreto; una excepción no debe copiarlo a _old_input en la sesión. */
    private function inputWithoutSecretFlash(Request $request): array
    {
        $data = $request->all();
        $request->request->remove('secret');
        $request->query->remove('secret');
        if ($request->isJson()) {
            $request->json()->remove('secret');
        }

        return $data;
    }
}
