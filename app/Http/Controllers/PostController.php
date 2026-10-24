<?php

namespace App\Http\Controllers;

use App\Connectors\Network;
use App\Enums\ContentType;
use App\Exceptions\PostValidationException;
use App\Http\Requests\PostRequest;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\ComposerPresetService;
use App\Services\MediaValidator;
use App\Services\PostService;
use App\Services\SavedTextService;
use App\Services\SocialAccountService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Compositor de publicaciones: crear, editar, programar y borrar pendientes de una marca. */
class PostController extends Controller
{
    use AuthorizesRequests;

    private const PUBLISHING_MESSAGE = 'Publicando ahora: se enviara en unos segundos.';

    private const PAUSED_MESSAGE = 'Hay destinos en pausa por la cuota o el limite de ritmo de la red: se enviaran cuando se libere, no ahora.';

    public function __construct(
        private readonly PostService $posts,
        private readonly SocialAccountService $accounts,
        private readonly MediaValidator $validator,
        private readonly ComposerPresetService $presets,
        private readonly SavedTextService $savedTexts,
    ) {}

    public function create(Request $request, Brand $brand): Response
    {
        $this->authorize('update', $brand);

        return $this->compose($request, $brand, null);
    }

    public function store(PostRequest $request, Brand $brand): RedirectResponse
    {
        $this->authorize('update', $brand);

        try {
            $post = $this->posts->create($request->user(), $brand, $request->validated());
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        // Interruptores recordados por (marca, red): solo desde el compositor web (el MCP no los escribe).
        $this->presets->remember($request->user(), $brand, $post->targets);

        return $this->afterSave($post, $request->boolean('publish_now') ? $this->publishingMessage($post) : 'Publicacion programada.', $request->boolean('publish_now'));
    }

    public function edit(Request $request, Post $post): Response
    {
        $this->authorize('update', $post);

        return $this->compose($request, Brand::withoutUserScope()->findOrFail($post->brand_id), $post);
    }

    public function update(PostRequest $request, Post $post): RedirectResponse
    {
        $this->authorize('update', $post);

        try {
            $post = $this->posts->update($request->user(), $post, $request->validated());
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        $this->presets->remember($request->user(), Brand::withoutUserScope()->findOrFail($post->brand_id), $post->targets);

        return $this->afterSave($post, $request->boolean('publish_now') ? $this->publishingMessage($post) : 'Publicacion actualizada.', $request->boolean('publish_now'));
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('delete', $post);

        try {
            $this->posts->delete($request->user(), $post);
        } catch (PostValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('brands.show', $post->brand_id)->with('success', 'Publicacion eliminada.');
    }

    /**
     * «Publicar ahora» desde fuera del compositor (calendario y lista): borradores y programadas, con la misma validacion y
     * las mismas defensas contra el doble despacho que el compositor. Un post ajeno es un 404 (UserScope + Policy).
     */
    public function publishNow(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('update', $post);

        try {
            $post = $this->posts->publishNow($request->user(), $post);
        } catch (PostValidationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $this->publishingMessage($post));
    }

    /**
     * Notas internas (C-09): editables aunque el post ya no lo sea. Un post ajeno es un 404 (UserScope + Policy). No toca los destinos
     * ni se audita su contenido.
     */
    public function updateNotes(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('update', $post);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:'.PostRequest::NOTES_MAX]]);
        $this->posts->updateNotes($request->user(), $post, $data['notes'] ?? null);

        return back()->with('success', 'Notas guardadas.');
    }

    /** Mensaje honesto: si algun destino sigue en pausa por cuota, no se promete «unos segundos». */
    private function publishingMessage(Post $post): string
    {
        return PostService::pausedTargets($post) > 0 ? self::PAUSED_MESSAGE : self::PUBLISHING_MESSAGE;
    }

    /** Validacion en vivo del formulario (sin guardar): errores de media y limites por red. */
    public function validatePost(PostRequest $request, Brand $brand): JsonResponse
    {
        $this->authorize('update', $brand);

        return response()->json(['errors' => $this->posts->validate($request->user(), $brand, $request->validated())]);
    }

    /** Programada -> al calendario, en la semana de la publicacion; borrador -> sigue en el compositor. */
    private function afterSave(Post $post, string $scheduledMessage, bool $publishedNow = false): RedirectResponse
    {
        if ($post->status->value === 'borrador' || $post->scheduled_at === null) {
            return redirect()->route('posts.edit', $post)->with('success', 'Borrador guardado.');
        }

        $brand = Brand::withoutUserScope()->findOrFail($post->brand_id);

        return redirect()->route('calendar.index', [
            'brand_id' => $post->brand_id,
            'view' => 'week',
            'date' => $post->scheduled_at->copy()->setTimezone($brand->timezone ?: 'UTC')->format('Y-m-d'),
        ])->with('success', $scheduledMessage)->with('publishing_post_id', $publishedNow ? $post->id : null);
    }

    private function compose(Request $request, Brand $brand, ?Post $post): Response
    {
        $user = $request->user();

        return Inertia::render('Posts/Compose', [
            'brand' => $brand->only(['id', 'name', 'timezone']),
            'accounts' => $this->accounts->listForBrand($user, $brand)->map(fn (SocialAccount $a) => [
                'id' => $a->id,
                'network' => $a->network->value,
                'network_label' => $a->network->label(),
                'name' => $a->name,
                'username' => $a->username,
                'status' => $a->status,
                'direct_post' => $a->network === Network::TikTok && ($a->meta['direct_post'] ?? false) === true,
            ])->values(),
            // Tipos por red con sus limitaciones (T-21): el formulario las avisa al elegir el tipo.
            'contentTypes' => collect(Network::cases())->mapWithKeys(fn (Network $n) => [
                $n->value => array_map(fn (ContentType $t) => [
                    'value' => $t->value,
                    'label' => $t->label(),
                    'description' => $t->description(),
                    'limitations' => $t->limitations(),
                ], array_values(array_filter(ContentType::forNetwork($n), fn (ContentType $t) => $t->isOffered()))),
            ]),
            // Nombre de todos los tipos (incluidos los que ya no se ofrecen): un post guardado con uno de ellos lo muestra tal cual.
            'typeLabels' => collect(ContentType::cases())->mapWithKeys(fn (ContentType $t) => [$t->value => $t->label()]),
            // Maximos de texto y hashtags por tipo (de `media_specs`): los contadores del cliente usan los del servidor.
            'limits' => collect(ContentType::cases())->filter->isOffered()->mapWithKeys(function (ContentType $t) {
                $text = $this->validator->spec($t)['text'];

                return [$t->value => ['max_chars' => $text['max'], 'max_hashtags' => $text['max_hashtags'] ?? null]];
            }),
            'presets' => $this->presets->forBrand($user, $brand), // interruptores recordados por red: valores iniciales al activar una red
            'savedTexts' => $this->savedTexts->forBrand($user, $brand->id), // textos guardados de la marca + «Todas las marcas» (C-01a)
            'post' => $post ? $this->postProps($post) : null,
            'flash' => ['success' => $request->session()->get('success')],
        ]);
    }

    /** @return array<string, mixed> */
    private function postProps(Post $post): array
    {
        $brand = Brand::withoutUserScope()->findOrFail($post->brand_id);
        $targets = $post->targets()->get();

        return [
            'id' => $post->id,
            'text' => $post->text,
            'notes' => $post->notes,
            'status' => $post->status->value,
            'status_label' => $post->status->label(),
            // Valor de <input type="datetime-local"> en la zona de la marca.
            'scheduled_at' => $post->scheduled_at?->copy()->setTimezone($brand->timezone ?: 'UTC')->format('Y-m-d\TH:i'),
            'editable' => ! $targets->contains(fn (PostTarget $t) => $t->status->isInFlight() || $t->status->isFinal()),
            'media' => $post->media()->get()->map->toPublicArray()->values(),
            'targets' => $targets->map(fn (PostTarget $t) => [
                'account_id' => $t->social_account_id,
                'content_type' => $t->content_type->value,
                'text' => $t->text, // texto propio del destino (null = vinculado a la plantilla)
                'options' => $t->options ?? new \stdClass,
                'status' => $t->status->value,
                'status_label' => $t->status->label(),
                'error_message' => $t->error_message,
                'status_note' => $t->status_note,
                'external_url' => $t->external_url,
            ])->values(),
        ];
    }
}
