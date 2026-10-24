<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavedTextRequest;
use App\Models\SavedText;
use App\Services\SavedTextService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

/** Ajustes · Textos guardados (C-01a): CRUD de fragmentos y grupos de hashtags del usuario. */
class SavedTextController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly SavedTextService $texts) {}

    public function store(SavedTextRequest $request): RedirectResponse
    {
        $this->texts->create($request->user(), $request->validated());

        return back()->with('success', 'Texto guardado.');
    }

    public function update(SavedTextRequest $request, int $id): RedirectResponse
    {
        $text = $this->find($id);
        $this->authorize('update', $text);
        $this->texts->update($text, $request->validated());

        return back()->with('success', 'Texto actualizado.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $text = $this->find($id);
        $this->authorize('delete', $text);
        $this->texts->delete($text);

        return back()->with('success', 'Texto eliminado.');
    }

    /** Sin UserScope a propósito: el texto de otro usuario debe responder 403 (y auditarse), no 404. */
    private function find(int $id): SavedText
    {
        return SavedText::withoutUserScope()->findOrFail($id);
    }
}
