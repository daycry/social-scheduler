<?php

namespace App\Http\Controllers;

use App\Http\Requests\CalendarViewRequest;
use App\Models\CalendarView;
use App\Services\CalendarViewService;
use Illuminate\Http\RedirectResponse;

/** Vistas guardadas del calendario (C-03a). El binding usa el UserScope: la vista de otro usuario responde 404. */
class CalendarViewController extends Controller
{
    public function __construct(private readonly CalendarViewService $views) {}

    public function store(CalendarViewRequest $request): RedirectResponse
    {
        $this->views->create($request->user(), $request->validated());

        return back()->with('success', 'Vista guardada.');
    }

    public function update(CalendarViewRequest $request, CalendarView $view): RedirectResponse
    {
        $this->views->update($view, $request->validated());

        return back()->with('success', 'Vista actualizada.');
    }

    public function destroy(CalendarView $view): RedirectResponse
    {
        $this->views->delete($view);

        return back()->with('success', 'Vista eliminada.');
    }
}
