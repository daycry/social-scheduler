<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\SmartLink;
use App\Models\SmartLinkBlock;
use App\Models\SmartLinkSubscriber;
use App\Services\SmartLinkService;
use App\Support\CsvWriter;
use App\Support\CurrentBrand;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
                'blocks' => $link->blocks->map(fn ($b) => [
                    'id' => $b->id, 'type' => $b->type, 'label' => $b->label, 'url' => $b->url, 'media_id' => $b->media_id, 'data' => $b->data,
                    'visible_from' => $b->visible_from?->setTimezone($brand->timezone ?: 'UTC')->format('Y-m-d\TH:i'),
                    'visible_until' => $b->visible_until?->setTimezone($brand->timezone ?: 'UTC')->format('Y-m-d\TH:i'),
                    'visibility' => $b->visible_from && $b->visible_from > now() ? 'scheduled' : ($b->visible_until && $b->visible_until <= now() ? 'expired' : 'visible'),
                ])->values(),
            ] : null,
            'stats' => $link ? $this->links->stats($link) : null,
            'subscribers' => $link ? SmartLinkSubscriber::query()->where('smart_link_id', $link->id)->orderByDesc('id')->limit(100)->get(['id', 'email', 'created_at'])->map(fn ($s) => ['id' => $s->id, 'email' => $s->email, 'at' => $s->created_at?->toIso8601String()])->values() : [],
            'images' => MediaAsset::query()->where('kind', MediaAsset::KIND_IMAGE)->where('status', MediaAsset::STATUS_READY)->latest()->limit(50)->get()->map(fn ($m) => $m->toPublicArray() + ['url' => $this->thumbUrl($m)])->values(),
            'defaults' => ['theme_color' => '#4f46e5', 'theme_mode' => 'light', 'max_blocks' => SmartLinkService::MAX_BLOCKS, 'timezone' => $brand?->timezone ?: 'UTC'],
            'block_types' => SmartLinkBlock::TYPES,
            'social_networks' => SmartLinkBlock::SOCIAL_NETWORKS,
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
            'blocks.*.type' => ['required', 'in:'.implode(',', SmartLinkBlock::TYPES)],
            'blocks.*.label' => ['nullable', 'string', 'max:100'],
            'blocks.*.url' => ['nullable', 'string', 'max:2000'],
            'blocks.*.media_id' => ['nullable', 'integer'],
            'blocks.*.data' => ['nullable', 'array'],
            'blocks.*.data.items' => ['nullable', 'array', 'max:8'],
            'blocks.*.data.items.*.network' => ['nullable', 'string', 'max:20'],
            'blocks.*.data.items.*.url' => ['nullable', 'string', 'max:2000'],
            'blocks.*.data.cta' => ['nullable', 'string', 'max:30'],
            'blocks.*.data.consent' => ['nullable', 'string', 'max:200'],
            'blocks.*.data.count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'blocks.*.visible_from' => ['nullable', 'string', 'max:40'],
            'blocks.*.visible_until' => ['nullable', 'string', 'max:40'],
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

    /** Suscriptores del formulario de email en CSV (solo el propietario), con neutralizacion de formulas. */
    public function subscribers(SmartLink $smartLink): StreamedResponse
    {
        $this->authorize('delete', $smartLink);
        $csv = CsvWriter::forConfiguredLocale();
        $rows = SmartLinkSubscriber::query()->where('smart_link_id', $smartLink->id)->orderBy('id')->limit(20000)->get(['email', 'created_at']);

        return response()->streamDownload(function () use ($rows, $csv): void {
            echo CsvWriter::BOM.$csv->line(['Email', 'Fecha (UTC)']);
            foreach ($rows as $r) {
                echo $csv->line([$r->email, $r->created_at?->utc()->format('Y-m-d H:i')]);
            }
        }, 'suscriptores-'.$smartLink->slug.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Borra un suscriptor de la lista (solo el propietario del SmartLink). */
    public function destroySubscriber(SmartLink $smartLink, SmartLinkSubscriber $subscriber): RedirectResponse
    {
        $this->authorize('delete', $smartLink);
        abort_unless($subscriber->smart_link_id === $smartLink->id, 404);
        $subscriber->delete();

        return back()->with('success', 'Suscriptor eliminado.');
    }

    /** Vacia la lista de suscriptores (solo el propietario). */
    public function clearSubscribers(SmartLink $smartLink): RedirectResponse
    {
        $this->authorize('delete', $smartLink);
        $n = SmartLinkSubscriber::query()->where('smart_link_id', $smartLink->id)->delete();

        return back()->with('success', "$n suscriptores eliminados.");
    }
}
