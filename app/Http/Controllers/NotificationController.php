<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Avisos in-app del usuario (fallos de publicacion, reconexiones). Siempre acotados al usuario autenticado. */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Notifications/Index', [
            'notifications' => $request->user()->notifications()->latest()->limit(100)->get()->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->data['type'] ?? 'info',
                'message' => $n->data['message'] ?? '',
                'network_label' => $n->data['network_label'] ?? ($n->data['network'] ?? null),
                'account_name' => $n->data['account_name'] ?? null,
                'brand_name' => $n->data['brand_name'] ?? null,
                'excerpt' => $n->data['excerpt'] ?? null,
                'scheduled_at' => $n->data['scheduled_at'] ?? null,
                'post_id' => $n->data['post_id'] ?? null,
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
