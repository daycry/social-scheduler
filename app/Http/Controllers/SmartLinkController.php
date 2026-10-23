<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SmartLink;
use App\Services\SmartLinkService;
use App\Support\CurrentBrand;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** SmartLinks · gestion (PA-07): el microsite de la marca elegida, sus bloques y sus estadisticas. */
class SmartLinkController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly SmartLinkService $links) {}

    public function index(Request $request): Response
    {
        $brand = CurrentBrand::resolve($request);
        $link = $brand ? SmartLink::query()->where('brand_id', $brand->id)->with('blocks')->first() : null;

        return Inertia::render('SmartLinks/Index', [
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name] : null,
            'link' => $link ? [
                'id' => $link->id,
                'slug' => $link->slug,
                'title' => $link->title,
                'bio' => $link->bio,
                'avatar_media_id' => $link->avatar_media_id,
                'theme_color' => $link->theme_color,
                'theme_mode' => $link->theme_mode,
                'published' => $link->published,
                'public_url' => $link->url(),
                'blocks' => $link->blocks->map(fn ($b) => ['id' => $b->id, 'type' => $b->type, 'label' => $b->label, 'url' => $b->url])->values(),
            ] : null,
            'stats' => $link ? $this->links->stats($link) : null,
            'images' => MediaAsset::query()->where('kind', MediaAsset::KIND_IMAGE)->where('status', MediaAsset::STATUS_READY)->latest()->limit(50)->get()->map(fn ($m) => $m->toPublicArray() + ['url' => $this->thumbUrl($m)])->values(),
            'defaults' => ['theme_color' => '#4f46e5', 'theme_mode' => 'light', 'max_blocks' => SmartLinkService::MAX_BLOCKS],
            'flash' => ['success' => $request->session()->get('success'), 'error' => $request->session()->get('error')],
        ]);
    }

    /** Crea o actualiza el microsite de la marca (una por marca). */
    public function save(Request $request): RedirectResponse
    {
        $current = null;
        if ($request->filled('brand_id')) {
            $current = SmartLink::query()->where('brand_id', (int) $request->input('brand_id'))->first();
        }

        $data = $request->validate([
            'brand_id' => ['required', 'integer'],
            'slug' => ['required', 'string', 'regex:/^'.SmartLink::SLUG_PATTERN.'$/', Rule::unique('smart_links', 'slug')->ignore($current?->id)],
            'title' => ['required', 'string', 'max:100'],
            'bio' => ['nullable', 'string', 'max:300'],
            'avatar_media_id' => ['nullable', 'integer'],
            'theme_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'theme_mode' => ['nullable', 'in:light,dark'],
            'published' => ['nullable', 'boolean'],
            'blocks' => ['nullable', 'array', 'max:'.SmartLinkService::MAX_BLOCKS],
            'blocks.*.id' => ['nullable', 'integer'],
            'blocks.*.type' => ['required', 'in:link,header'],
            'blocks.*.label' => ['required', 'string', 'max:100'],
            'blocks.*.url' => ['nullable', 'string', 'max:2000'],
        ], [
            'slug.regex' => 'Usa de 3 a 40 caracteres: minusculas, numeros y guiones (sin guion al principio ni al final).',
        ]);

        $brand = Brand::query()->findOrFail((int) $data['brand_id']); // UserScope: una marca ajena es 404

        try {
            $link = $this->links->save($request->user(), $brand, $data);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('smartlinks.index', ['brand_id' => $brand->id])->with('success', 'SmartLink guardado: '.$link->url());
    }

    public function destroy(SmartLink $smartLink): RedirectResponse
    {
        $this->authorize('delete', $smartLink);
        $brandId = $smartLink->brand_id;
        $smartLink->delete();

        return redirect()->route('smartlinks.index', ['brand_id' => $brandId])->with('success', 'SmartLink eliminado.');
    }

    /** URL temporal para la vista previa del avatar; si el disco no puede firmarla, la vista previa usa la inicial. */
    private function thumbUrl(MediaAsset $m): ?string
    {
        try {
            return $m->publicUrl(60);
        } catch (\Throwable) {
            return null;
        }
    }
}
