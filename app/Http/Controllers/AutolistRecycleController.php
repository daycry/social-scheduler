<?php

namespace App\Http\Controllers;

use App\Exceptions\PostValidationException;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Autolists\RecycleService;
use App\Support\CurrentBrand;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Planificacion · Reciclado inteligente (T-92): los mejores posts de la marca para volver a publicarlos como borrador. */
class AutolistRecycleController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly RecycleService $recycling) {}

    public function index(Request $request): Response
    {
        $brand = CurrentBrand::resolve($request);
        $result = $brand ? $this->recycling->proposals($request->user(), $brand) : ['proposals' => [], 'total' => 0, 'not_analyzed' => 0, 'excluded' => [], 'rules' => RecycleService::rules()];
        $accounts = $brand ? SocialAccount::ownedBy($request->user()->id)->where('brand_id', $brand->id)->get(['id', 'name', 'network'])->keyBy('id') : collect();

        return Inertia::render('Autolists/Recycle', [
            'brand' => $brand ? ['id' => $brand->id, 'name' => $brand->name, 'timezone' => $brand->timezone ?: 'UTC'] : null,
            'proposals' => collect($result['proposals'])->map(fn (array $p) => array_replace($p, [
                'targets' => collect($p['targets'])->map(fn (array $t) => array_replace($t, ['account' => $accounts->get($t['account_id'])?->name]))->all(),
            ]))->all(),
            'total' => $result['total'],
            'not_analyzed' => $result['not_analyzed'],
            'excluded' => $result['excluded'],
            'rules' => $result['rules'],
        ]);
    }

    /** «Reciclar»: crea un borrador con el mismo contenido y abre su editor (nada se publica ni se programa). */
    public function store(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('update', $post);

        try {
            $result = $this->recycling->recycle($request->user(), $post);
        } catch (PostValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = 'Publicación reciclada como borrador. Revisa el texto y elige cuándo publicarla.';
        if ($result->omitted > 0) {
            $message .= ' '.$result->omitted.' destino'.($result->omitted === 1 ? '' : 's').' sin copiar: revisa las cuentas del borrador.';
        }

        $labels = ['instagram_audio' => 'el audio de Instagram', 'privacy_level' => 'la visibilidad de TikTok'];
        foreach ($result->resetOptions as $option) {
            $message .= ' Elige de nuevo '.($labels[$option] ?? 'las opciones de la cuenta').'.';
        }

        return redirect()->route('posts.edit', $result->post)->with('success', $message);
    }

    /** «Descartar»: la propuesta se oculta hasta pasado el intervalo mínimo. */
    public function dismiss(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('update', $post);

        try {
            $this->recycling->dismiss($request->user(), $post);
        } catch (PostValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Propuesta descartada. Volverá a valorarse pasado el intervalo mínimo.');
    }
}
