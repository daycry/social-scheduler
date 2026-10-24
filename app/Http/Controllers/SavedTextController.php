<?php

namespace App\Http\Controllers;

use App\Http\Requests\SavedTextRequest;
use App\Models\SavedText;
use App\Services\SavedTextService;
use App\Support\CurrentBrand;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Ajustes · Textos guardados (C-01a): CRUD de fragmentos y grupos de hashtags del usuario. */
class SavedTextController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly SavedTextService $texts) {}

    public function index(Request $request): Response
    {
        return Inertia::render('SavedTexts/Index', [
            'texts' => $this->texts->list($request->user())->map(fn (SavedText $t) => $this->texts->toArray($t))->values(),
            'brands' => CurrentBrand::brands($request)->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'kinds' => ['fragmento' => 'Fragmento', 'hashtags' => 'Grupo de hashtags'],
            'flash' => ['success' => $request->session()->get('success'), 'error' => $request->session()->get('error')],
        ]);
    }

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
