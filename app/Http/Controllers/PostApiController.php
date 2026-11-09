<?php

namespace App\Http\Controllers;

use App\Enums\PostStatus;
use App\Exceptions\InvalidPostTransition;
use App\Exceptions\PostValidationException;
use App\Http\Requests\PostRequest;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\AuditLog;
use App\Services\PostPresenter;
use App\Services\PostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * API REST de publicaciones (T-17): el mismo contrato que el compositor web y el MCP (`PostRequest` + `PostService` + `PostPresenter`).
 * `media` es la media comun y `targets[].media` la propia de cada destino (null/ausente = hereda la comun).
 */
class PostApiController extends Controller
{
    public function __construct(private readonly PostService $posts, private readonly PostPresenter $presenter) {}

    public function show(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id, 404);
        $this->audit($request, 'get_post', AuditLog::OK, $post->id);

        return response()->json($this->presenter->present($post));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request->all(), ['brand_id' => ['required', 'integer']]);
        $brand = Brand::withoutUserScope()->where('user_id', $request->user()->id)->find($data['brand_id']);
        abort_if($brand === null, 404);
        unset($data['brand_id']);

        try {
            $post = $this->posts->create($request->user(), $brand, $data);
        } catch (PostValidationException $e) {
            $this->audit($request, 'create_post', AuditLog::ERROR);

            return $this->invalid($e);
        }
        $this->audit($request, 'create_post', AuditLog::OK, $post->id);

        return response()->json($this->presenter->present($post), 201);
    }

    /**
     * PUT reemplaza el post entero; PATCH fusiona lo enviado sobre el estado actual (los destinos y la media se envian enteros si se
     * envian) y valida el resultado con el mismo `PostRequest`. Solo `notes` y `campaign_id` tambien se aceptan en un post ya publicado.
     */
    public function update(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id, 404);
        $user = $request->user();

        try {
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
                    $this->audit($request, 'update_post', AuditLog::OK, $post->id);

                    return response()->json($this->presenter->present($post));
                }
                $data = $this->validated(array_replace($this->current($post), $sent), []);
            } else {
                $data = $this->validated($request->all(), []);
            }
            $post = $this->posts->update($user, $post, $data);
        } catch (PostValidationException $e) {
            $this->audit($request, 'update_post', AuditLog::ERROR, $post->id);

            return $this->invalid($e);
        } catch (InvalidPostTransition $e) {
            $this->audit($request, 'update_post', AuditLog::ERROR, $post->id);

            return response()->json(['message' => $e->getMessage()], 409);
        }
        $this->audit($request, 'update_post', AuditLog::OK, $post->id);

        return response()->json($this->presenter->present($post));
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
                'text' => $t->text, 'first_comment' => $t->first_comment, 'media' => $t->media,
            ])->all(),
        ];
    }

    private function audit(Request $request, string $action, string $outcome, ?int $postId = null): void
    {
        app(AuditLog::class)->record($request->user(), $action, $outcome, 'post', $postId, null, 'api');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function validated(array $input, array $only = []): array
    {
        return Validator::make($input, $only + (new PostRequest)->rules())->validate();
    }

    private function invalid(PostValidationException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
    }
}
