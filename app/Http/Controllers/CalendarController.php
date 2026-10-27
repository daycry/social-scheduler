<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\CalendarView;
use App\Models\Post;
use App\Services\CalendarService;
use App\Services\CalendarViewService;
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
        private readonly CalendarViewService $views,
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
            'saved_view' => ['nullable', 'integer'],
        ]);

        // Vista guardada (C-03a): `saved_view=<id>` aplica sus filtros (mandan sobre los de la URL; la fecha se conserva). La vista
        // por defecto se abre cuando el usuario no ha elegido nada: `brand_id` y `view` NO cuentan (los enlaces del menú los llevan
        // siempre); sí cuentan red, estado, búsqueda y fecha (los pone quien filtra o navega por el calendario). La de otro usuario da 404.
        $user = $request->user();
        $active = null;
        $isDefault = false;
        if (! empty($filters['saved_view'])) {
            $active = CalendarView::query()->findOrFail((int) $filters['saved_view']);
        } elseif (! $request->hasAny(['date', 'network', 'status', 'q', 'saved_view'])) {
            $active = $this->views->default($user);
            $isDefault = $active !== null;
        }
        if ($active) {
            $applied = $this->views->filtersFor($user, $active);
            if ($isDefault) {
                // Entrando desde el menú, la marca actual y la vista (semana/lista) que pide el enlace mandan sobre las de la vista por defecto.
                $applied = array_diff_key($applied, array_filter(['brand_id' => ! empty($filters['brand_id']), 'view' => ! empty($filters['view'])]));
                $filters = array_merge(array_diff_key($filters, array_flip(['network', 'status'])), $applied);
            } else {
                $filters = array_merge(array_diff_key($filters, array_flip(['brand_id', 'network', 'status', 'view'])), $applied);
            }
        }

        try {
            $data = $this->calendar->build($user, $filters);
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
            'views' => $this->views->list($user)->map(fn (CalendarView $v) => $this->views->toArray($v))->values(),
            'active_view' => $active?->id,
            'flash' => ['success' => $request->session()->get('success'), 'error' => $request->session()->get('error'), 'publishing_post_id' => $request->session()->get('publishing_post_id')],
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
