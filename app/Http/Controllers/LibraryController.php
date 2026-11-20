<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\SocialAccount;
use App\Services\LibraryService;
use App\Support\CurrentBrand;
use App\Support\CursorPage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Planificación · Plantillas de publicación (PA-06): plantillas reutilizables de texto + media + redes. */
class LibraryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly LibraryService $library) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $filters = $request->validate(CursorPage::RULES);
        $brand = CurrentBrand::resolve($request);
        $query = PostTemplate::query()
            ->when($brand, fn ($q) => $q->where('brand_id', $brand->id))
            ->with(['brand:id,name', 'media'])->latest('id');
        [$templates, $pagination] = CursorPage::fetch(
            $query, $filters['limit'] ?? null, $filters['cursor'] ?? null,
            'library:u'.$request->user()->id.':b'.($brand?->id ?? 'all'), withTotal: true,
        );
        if ($templates->isEmpty() && filled($filters['cursor'] ?? null)) {
            return to_route('library.index', ['brand_id' => $brand?->id, 'limit' => $filters['limit'] ?? null]);
        }
        $accountIds = $templates->flatMap(fn (PostTemplate $t) => collect((array) $t->targets)->pluck('account_id'))->unique()->filter()->values();
        $networkByAccount = SocialAccount::query()->whereIn('id', $accountIds)->get()->mapWithKeys(fn (SocialAccount $a) => [$a->id => $a->network->value]);

        return Inertia::render('Library/Index', [
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name] : null,
            'pagination' => $pagination + $filters,
            'csrfToken' => fn () => $request->session()->token(),
            'templates' => $templates->map(fn (PostTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'text' => $t->text,
                'brand' => $t->brand?->name,
                'brand_id' => $t->brand_id,
                'media' => $t->media->map(fn ($m) => $m->toPublicArray())->values(),
                'targets' => count((array) $t->targets),
                'networks' => collect((array) $t->targets)->map(fn ($x) => $networkByAccount[$x['account_id'] ?? 0] ?? null)->filter()->unique()->values(),
                'created_at' => $t->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /** Guarda una publicacion existente (`post_id`) o un texto nuevo (`brand_id` + `text`) como plantilla. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'post_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer', 'required_without:post_id'],
            'text' => ['nullable', 'string', 'max:10000'],
        ]);

        try {
            if (! empty($data['post_id'])) {
                $post = Post::withTrashed()->findOrFail((int) $data['post_id']); // UserScope: una ajena es 404
                $this->authorize('view', $post);
                $this->library->saveFromPost($request->user(), $post, $data['name']);
            } else {
                $brand = Brand::query()->findOrFail((int) $data['brand_id']);
                $this->library->store($request->user(), $brand, $data['name'], $data['text'] ?? null);
            }
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('library.index')->with('success', 'Plantilla guardada.');
    }

    /**
     * «Guardar como plantilla» desde el compositor (CA-26): copia el contenido actual (texto, media y destinos) aunque el post no
     * este guardado o tenga cambios pendientes. No crea ningun post y deja al usuario en el compositor.
     */
    public function storeFromComposer(Request $request, Brand $brand): RedirectResponse
    {
        $this->authorize('update', $brand);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'text' => ['nullable', 'string', 'max:70000'],
            'campaign_id' => ['nullable', 'integer'], // C-03: la plantilla conserva la campaña; la marca la valida LibraryService::store()
            'media' => ['nullable', 'array', 'max:35'],
            'media.*' => ['integer'],
            'targets' => ['nullable', 'array', 'max:40'],
            'targets.*.account_id' => ['required', 'integer'],
            'targets.*.content_type' => ['required', 'string', 'max:40'],
            'targets.*.text' => ['nullable', 'string', 'max:70000'],
        ]);

        try {
            $this->library->saveFromComposer($request->user(), $brand, $data['name'], $data);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Plantilla guardada.');
    }

    /** Crea un borrador con el contenido de la plantilla y abre el editor. */
    public function use(Request $request, PostTemplate $template): RedirectResponse
    {
        $this->authorize('view', $template);

        try {
            $post = $this->library->use($request->user(), $template, $notice);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('posts.edit', $post)->with('success', trim('Borrador creado desde la plantilla. '.$notice));
    }

    public function destroy(PostTemplate $template): RedirectResponse
    {
        $this->authorize('delete', $template);
        $template->delete();

        return redirect()->route('library.index')->with('success', 'Plantilla eliminada.');
    }
}
