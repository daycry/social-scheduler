<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\Post;
use App\Services\CalendarService;
use App\Services\PostService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/** Calendario (semana / mes / lista) por marca y red, con reprogramacion de pendientes. */
class CalendarController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly CalendarService $calendar,
        private readonly PostService $posts,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'view' => ['nullable', 'in:week,month,list'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'brand_id' => ['nullable', 'integer'],
            'network' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'string', 'max:20'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $data = $this->calendar->build($request->user(), $filters);
        } catch (InvalidArgumentException $e) {
            abort(404, $e->getMessage());
        }

        return Inertia::render('Calendar/Index', $data + [
            'filters' => [
                'brand_id' => $filters['brand_id'] ?? null,
                'network' => $filters['network'] ?? null,
                'status' => $filters['status'] ?? null,
                'q' => $filters['q'] ?? null,
            ],
            'flash' => ['success' => $request->session()->get('success'), 'error' => $request->session()->get('error')],
        ]);
    }

    /** Reprograma una publicacion pendiente (arrastrar en el calendario o editar la fecha en la lista). */
    public function reschedule(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('update', $post);
        $data = $request->validate(['scheduled_at' => ['required', 'string', 'max:40']]);

        try {
            $this->posts->reschedule($request->user(), $post, $data['scheduled_at']);
        } catch (PostValidationException $e) {
            return back()->with('error', $e->getMessage());
        } catch (InvalidArgumentException) {
            return back()->with('error', 'La fecha no es valida.');
        }

        return back()->with('success', 'Publicacion reprogramada.');
    }
}
