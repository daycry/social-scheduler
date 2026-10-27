<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalendarViewRequest;
use App\Models\CalendarView;
use App\Services\CalendarViewService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Vistas guardadas del calendario (C-03a). El binding usa el UserScope: la vista de otro usuario responde 404. */
class CalendarViewController extends Controller
{
    public function __construct(private readonly CalendarViewService $views) {}

    public function store(CalendarViewRequest $request): RedirectResponse
    {
        if ($this->views->list($request->user())->count() >= CalendarViewService::MAX_PER_USER) {
            return back()->withErrors(['name' => 'Has llegado al máximo de '.CalendarViewService::MAX_PER_USER.' vistas guardadas. Borra alguna para crear otra.']);
        }

        $this->views->create($request->user(), $request->validated());

        return back()->with('success', 'Vista guardada.');
    }

    public function update(CalendarViewRequest $request, CalendarView $view): RedirectResponse
    {
        $this->views->update($view, $request->validated());

        return back()->with('success', 'Vista actualizada.');
    }

    public function destroy(Request $request, CalendarView $view): RedirectResponse
    {
        $id = $view->id;
        $this->views->delete($view);

        // Si el calendario estaba abierto con esa vista (`saved_view=<id>`), volver ahi daria 404: se vuelve al calendario sin ella.
        parse_str((string) parse_url((string) $request->headers->get('referer'), PHP_URL_QUERY), $query);
        if ((int) ($query['saved_view'] ?? 0) === $id) {
            return redirect()->route('calendar.index')->with('success', 'Vista eliminada.');
        }

        return back()->with('success', 'Vista eliminada.');
    }
}
