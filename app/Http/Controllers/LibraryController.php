<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\SocialAccount;
use App\Services\LibraryService;
use App\Support\CurrentBrand;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Planificacion · Biblioteca (PA-06): plantillas reutilizables de texto + media + redes. */
class LibraryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly LibraryService $library) {}

    public function index(Request $request): Response
    {
        $brand = CurrentBrand::resolve($request);
        $templates = PostTemplate::query()
            ->when($brand, fn ($q) => $q->where('brand_id', $brand->id))
            ->with(['brand:id,name', 'media'])->latest()->limit(200)->get();
        $accountIds = $templates->flatMap(fn (PostTemplate $t) => collect((array) $t->targets)->pluck('account_id'))->unique()->filter()->values();
        $networkByAccount = SocialAccount::query()->whereIn('id', $accountIds)->get()->mapWithKeys(fn (SocialAccount $a) => [$a->id => $a->network->value]);

        return Inertia::render('Library/Index', [
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name] : null,
            'templates' => $templates->map(fn (PostTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'text' => $t->text,
                'brand' => $t->brand?->name,
                'media' => $t->media->map(fn ($m) => $m->toPublicArray())->values(),
                'targets' => count((array) $t->targets),
                'networks' => collect((array) $t->targets)->map(fn ($x) => $networkByAccount[$x['account_id'] ?? 0] ?? null)->filter()->unique()->values(),
                'created_at' => $t->created_at?->toIso8601String(),
            ])->values(),
            'flash' => ['success' => $request->session()->get('success'), 'error' => $request->session()->get('error')],
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

        return redirect()->route('library.index')->with('success', 'Guardado en la biblioteca.');
    }

    /** Crea un borrador con el contenido de la plantilla y abre el editor. */
    public function use(Request $request, PostTemplate $template): RedirectResponse
    {
        $this->authorize('view', $template);

        try {
            $post = $this->library->use($request->user(), $template);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('posts.edit', $post)->with('success', 'Borrador creado desde la plantilla.');
    }

    public function destroy(PostTemplate $template): RedirectResponse
    {
        $this->authorize('delete', $template);
        $template->delete();

        return redirect()->route('library.index')->with('success', 'Plantilla eliminada.');
    }
}
