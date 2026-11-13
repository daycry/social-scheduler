<?php

namespace App\Http\Controllers\Api;

use App\Enums\PostStatus;
use App\Exceptions\PostStateConflict;
use App\Http\Requests\PostRequest;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\AuditLog;
use App\Services\PostPresenter;
use App\Services\PostService;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * API REST de publicaciones (T-17): el mismo contrato que el compositor web y el MCP (`PostRequest` + `PostService` + `PostPresenter`).
 * `media` es la media comun y `targets[].media` la propia de cada destino (null/ausente = hereda la comun).
 */
class PostApiController extends ApiController
{
    public function __construct(private readonly PostService $posts, private readonly PostPresenter $presenter) {}

    public function show(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id, 404);
        $this->audit($request, 'get_post', AuditLog::OK, 'post', $post->id);

        return response()->json($this->presenter->present($post));
    }

    /** Publicaciones de la marca, las mas recientes primero, con el contrato de cursor comun (`limit`, `cursor`, `has_more`, `next_cursor`). */
    public function index(Request $request, int $brand): JsonResponse
    {
        $brand = $this->ownedBrand($request, $brand);
        $filters = $request->validate(CursorPage::RULES + ['status' => ['nullable', Rule::enum(PostStatus::class)]]);
        $query = Post::withoutUserScope()->where('user_id', $request->user()->id)->where('brand_id', $brand->id)
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->with(['brand', 'campaign', 'targets', 'media'])->latest('id');
        [$rows, $page] = CursorPage::fetch($query, $filters['limit'] ?? null, $filters['cursor'] ?? null, 'posts:u'.$request->user()->id.':b'.$brand->id);
        $this->audit($request, 'list_posts', AuditLog::OK, 'brand', $brand->id);

        return response()->json(['posts' => $rows->map(fn (Post $p) => $this->presenter->present($p))->values()->all()] + $page);
    }

    public function store(Request $request, int $brand): JsonResponse
    {
        $brand = $this->ownedBrand($request, $brand);

        return $this->respond($request, 'create_post', 'post', null, fn () => $this->presenter->present($this->posts->create($request->user(), $brand, $this->validated($request->all()))), 201);
    }

    /** Borra una publicacion aun no publicada (como `delete_post` del MCP y la papelera de la web). */
    public function destroy(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id, 404);

        return $this->respond($request, 'delete_post', 'post', $post->id, function () use ($request, $post) {
            $this->posts->delete($request->user(), $post);

            return ['id' => $post->id, 'deleted' => true];
        });
    }

    /** Cancela la programacion: la publicacion pendiente vuelve a borrador. */
    public function cancel(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id, 404);

        return $this->respond($request, 'cancel_post', 'post', $post->id, function () use ($request, $post) {
            // Cancelar solo tiene sentido con algo programado: un borrador (ya cancelado) no admite la operacion.
            if ($post->status === PostStatus::Draft) {
                throw new PostStateConflict('La publicacion ya es un borrador: no hay programacion que cancelar.');
            }

            return $this->presenter->present($this->posts->unschedule($request->user(), $post));
        });
    }

    /** Publica ya un borrador o una programada: se valida como al programar y se encola al instante. */
    public function publish(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id, 404);

        return $this->respond($request, 'publish_post_now', 'post', $post->id, fn () => $this->presenter->present($this->posts->publishNow($request->user(), $post)), 202);
    }

    /**
     * PUT reemplaza el post entero; PATCH fusiona lo enviado sobre el estado actual (los destinos y la media se envian enteros si se
     * envian) y valida el resultado con el mismo `PostRequest`. Solo `notes` y `campaign_id` tambien se aceptan en un post ya publicado.
     */
    public function update(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id, 404);

        return $this->respond($request, 'update_post', 'post', $post->id, fn () => $this->presenter->present($this->applyUpdate($request, $post)));
    }

    private function applyUpdate(Request $request, Post $post): Post
    {
        $user = $request->user();
        if ($request->isMethod('PATCH')) {
            $sent = $request->all();
            if ($sent !== [] && array_diff(array_keys($sent), ['notes', 'campaign_id']) === []) {
                Validator::make($sent, array_intersect_key((new PostRequest)->rules(), $sent))->validate();
                if (array_key_exists('notes', $sent)) {
                    $post = $this->posts->updateNotes($user, $post, $sent['notes']);
                }
                if (array_key_exists('campaign_id', $sent)) {
                    $post = $this->posts->updateCampaign($user, $post, $sent['campaign_id'] === null || $sent['campaign_id'] === '' ? null : (int) $sent['campaign_id']);
                }

                return $post;
            }

            return $this->posts->update($user, $post, $this->validated(array_replace($this->current($post), $sent)));
        }

        return $this->posts->update($user, $post, $this->validated($request->all()));
    }

    /**
     * El post como entrada de `PostService::update()`: la base de un PATCH.
     *
     * @return array<string, mixed>
     */
    private function current(Post $post): array
    {
        $tz = Brand::withoutUserScope()->whereKey($post->brand_id)->value('timezone') ?: 'UTC';

        return [
            'text' => $post->text,
            'notes' => $post->notes,
            'campaign_id' => $post->campaign_id,
            'schedule' => $post->status !== PostStatus::Draft,
            'scheduled_at' => $post->scheduled_at?->copy()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'media' => $post->media()->pluck('media_assets.id')->all(),
            'targets' => $post->targets()->get()->map(fn (PostTarget $t) => [
                'account_id' => $t->social_account_id, 'content_type' => $t->content_type->value, 'options' => $t->options ?? [],
                'text' => $t->text, 'first_comment' => $t->first_comment, 'media' => $t->media, 'manual' => $t->manual,
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function validated(array $input): array
    {
        return Validator::make($input, (new PostRequest)->rules())->validate();
    }
}
