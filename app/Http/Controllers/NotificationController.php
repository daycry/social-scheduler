<?php

namespace App\Http\Controllers;

use App\Support\CursorPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Avisos in-app del usuario (fallos de publicacion, reconexiones). Siempre acotados al usuario autenticado. */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(CursorPage::RULES);
        $user = $request->user();
        $query = $user->notifications()->getQuery()->reorder()->orderByDesc('created_at')->orderByDesc('id');
        [$notifications, $pagination] = CursorPage::fetch(
            $query, $filters['limit'] ?? null, $filters['cursor'] ?? null,
            'notifications:u'.$user->id, withTotal: true, cursorRules: ['id' => ['required', 'uuid']],
        );

        return Inertia::render('Notifications/Index', [
            'pagination' => $pagination + $filters,
            'notifications' => $notifications->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->data['type'] ?? 'info',
                'title' => $n->data['title'] ?? null,
                'count' => $n->data['count'] ?? null,
                'message' => $n->data['message'] ?? '',
                'network_label' => $n->data['network_label'] ?? ($n->data['network'] ?? null),
                'account_name' => $n->data['account_name'] ?? null,
                'brand_name' => $n->data['brand_name'] ?? null,
                'excerpt' => $n->data['excerpt'] ?? null,
                'scheduled_at' => $n->data['scheduled_at'] ?? null,
                'post_id' => $n->data['post_id'] ?? null,
                'url' => $n->data['url'] ?? null,
                'brand_id' => $n->data['brand_id'] ?? null,
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function read(Request $request, string $id): RedirectResponse
    {
        $request->user()->notifications()->whereKey($id)->firstOrFail()->markAsRead();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
