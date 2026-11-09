<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidPostTransition;
use App\Exceptions\PostValidationException;
use App\Http\Requests\PostRequest;
use App\Models\Brand;
use App\Models\Post;
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

        return response()->json($this->presenter->present($post));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, ['brand_id' => ['required', 'integer']]);
        $brand = Brand::withoutUserScope()->where('user_id', $request->user()->id)->find($data['brand_id']);
        abort_if($brand === null, 404);
        unset($data['brand_id']);

        try {
            $post = $this->posts->create($request->user(), $brand, $data);
        } catch (PostValidationException $e) {
            return $this->invalid($e);
        }

        return response()->json($this->presenter->present($post), 201);
    }

    public function update(Request $request, Post $post): JsonResponse
    {
        abort_unless($post->user_id === $request->user()->id, 404);
        $data = $this->validated($request, []);

        try {
            $post = $this->posts->update($request->user(), $post, $data);
        } catch (PostValidationException $e) {
            return $this->invalid($e);
        } catch (InvalidPostTransition $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($this->presenter->present($post));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $extra): array
    {
        return Validator::make($request->all(), $extra + (new PostRequest)->rules())->validate();
    }

    private function invalid(PostValidationException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
    }
}
