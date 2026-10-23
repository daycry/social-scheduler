<?php

namespace App\Http\Controllers;

use App\Enums\ContentType;
use App\Exceptions\PostValidationException;
use App\Models\Autolist;
use App\Models\AutolistItem;
use App\Models\Brand;
use App\Models\PostTemplate;
use App\Models\SocialAccount;
use App\Services\AutolistService;
use App\Support\CurrentBrand;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Planificacion · Autolistas (PA-09): colas de contenido con franjas semanales que generan publicaciones programadas. */
class AutolistController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly AutolistService $autolists) {}

    public function index(Request $request): Response
    {
        $brand = CurrentBrand::resolve($request);
        $lists = $brand ? Autolist::query()->where('brand_id', $brand->id)->with('items.template:id,name,text')->orderBy('name')->get() : collect();
        $accounts = $brand
            ? SocialAccount::query()->where('brand_id', $brand->id)->where('status', '!=', SocialAccount::STATUS_DISCONNECTED)->orderBy('network')->orderBy('name')->get()
            : collect();

        return Inertia::render('Autolists/Index', [
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name, 'timezone' => $brand->timezone ?: 'UTC'] : null,
            'accounts' => $accounts->map(fn (SocialAccount $a) => [
                'id' => $a->id, 'network' => $a->network->value, 'name' => $a->name,
                'content_types' => collect(ContentType::forNetwork($a->network))->filter(fn (ContentType $t) => $t->isOffered())
                    ->map(fn (ContentType $t) => ['value' => $t->value, 'label' => $t->label()])->values(),
            ])->values(),
            'templates' => $brand ? PostTemplate::query()->where('brand_id', $brand->id)->orderBy('name')->limit(200)->get(['id', 'name'])->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values() : [],
            'autolists' => $lists->map(fn (Autolist $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'active' => $l->active,
                'recycle' => $l->recycle,
                'slots' => $l->slots,
                'targets' => $l->targets,
                'cursor' => $l->cursor,
                'last_generated_at' => $l->last_generated_at?->toIso8601String(),
                'items' => $l->items->map(fn (AutolistItem $i) => [
                    'id' => $i->id,
                    'label' => $i->template?->name ?? mb_substr((string) $i->text, 0, 80),
                    'is_template' => $i->post_template_id !== null,
                    'times_used' => $i->times_used,
                ])->values(),
            ])->values(),
            'flash' => ['success' => $request->session()->get('success'), 'error' => $request->session()->get('error')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'recycle' => ['boolean'],
            'targets' => ['required', 'array', 'min:1', 'max:20'],
            'targets.*.account_id' => ['required', 'integer'],
            'targets.*.content_type' => ['required', 'string'],
            'slots' => ['required', 'array', 'min:1', 'max:'.AutolistService::MAX_SLOTS],
            'slots.*.day' => ['required', 'integer', 'between:1,7'],
            'slots.*.time' => ['required', 'date_format:H:i'],
        ]);
        $brand = Brand::query()->findOrFail((int) $data['brand_id']); // UserScope: una marca ajena es 404

        try {
            $this->autolists->create($request->user(), $brand, $data['name'], $data['targets'], $data['slots'], (bool) ($data['recycle'] ?? false));
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('autolists.index', ['brand_id' => $brand->id])->with('success', 'Autolista creada. Añade contenido a la cola.');
    }

    /** Activa/pausa la lista o cambia el reciclado. */
    public function update(Request $request, Autolist $autolist): RedirectResponse
    {
        $this->authorize('update', $autolist);
        $data = $request->validate(['active' => ['sometimes', 'boolean'], 'recycle' => ['sometimes', 'boolean']]);
        $autolist->forceFill($data)->save();

        return back()->with('success', 'Autolista actualizada.');
    }

    public function destroy(Autolist $autolist): RedirectResponse
    {
        $this->authorize('delete', $autolist);
        $brandId = $autolist->brand_id;
        $autolist->delete();

        return redirect()->route('autolists.index', ['brand_id' => $brandId])->with('success', 'Autolista eliminada. Las publicaciones ya generadas se conservan.');
    }

    public function addItem(Request $request, Autolist $autolist): RedirectResponse
    {
        $this->authorize('update', $autolist);
        $data = $request->validate([
            'post_template_id' => ['nullable', 'integer'],
            'text' => ['nullable', 'string', 'max:10000'],
            'media' => ['nullable', 'array', 'max:10'],
            'media.*' => ['integer'],
        ]);

        try {
            $this->autolists->addItem($request->user(), $autolist, isset($data['post_template_id']) ? (int) $data['post_template_id'] : null, $data['text'] ?? null, $data['media'] ?? []);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Añadido a la cola.');
    }

    public function removeItem(Autolist $autolist, int $item): RedirectResponse
    {
        $this->authorize('update', $autolist);
        AutolistItem::withoutUserScope()->where('autolist_id', $autolist->id)->whereKey($item)->firstOrFail()->delete();

        return back()->with('success', 'Elemento quitado de la cola.');
    }

    /** «Generar ahora»: crea ya las publicaciones de los próximos días (el job diario hace lo mismo). */
    public function generate(Autolist $autolist): RedirectResponse
    {
        $this->authorize('update', $autolist);
        $result = $this->autolists->generate($autolist);

        return back()->with('success', "Generadas {$result['created']} publicaciones programadas".($result['skipped'] ? " ({$result['skipped']} franjas omitidas por contenido no válido)" : '').'.');
    }
}
