<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Studio\Design;
use App\Services\Studio\StudioCollab;
use App\Services\Studio\StudioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Compartir, enlaces, comentarios y versiones de un diseño; las mismas acciones sirven a la web (sesión) y a la API (Sanctum). */
class StudioCollabController extends Controller
{
    public function __construct(private readonly StudioCollab $collab, private readonly StudioService $studio) {}

    public function state(Request $request, Design $design): JsonResponse
    {
        $user = $request->user();
        $this->collab->authorize($user, $design, 'view');
        $access = $this->collab->access($user, $design);
        $owner = $access['is_owner'];

        return response()->json(['access' => $access, 'revision' => $design->revision,
            'shares' => $owner ? $this->collab->shares($user, $design) : [], 'links' => $owner ? $this->collab->links($user, $design) : [],
            'comments' => $this->commentRows($request, $design), 'versions' => $this->studio->versions($user, $design)]);
    }

    public function shares(Request $request, Design $design): JsonResponse
    {
        return response()->json(['shares' => $this->collab->shares($request->user(), $design)]);
    }

    public function share(Request $request, Design $design): JsonResponse
    {
        $share = $this->collab->share($request->user(), $design, $request->input('email'), $request->input('role'));

        return response()->json(['share' => $this->collab->presentShare($share)], $share->wasRecentlyCreated ? 201 : 200);
    }

    public function updateShare(Request $request, Design $design, int $share): JsonResponse
    {
        return response()->json(['share' => $this->collab->presentShare($this->collab->updateShare($request->user(), $design, $share, $request->input('role')))]);
    }

    public function revokeShare(Request $request, Design $design, int $share): JsonResponse
    {
        $this->collab->revokeShare($request->user(), $design, $share);

        return response()->json(['revoked' => true]);
    }

    public function links(Request $request, Design $design): JsonResponse
    {
        return response()->json(['links' => $this->collab->links($request->user(), $design)]);
    }

    public function createLink(Request $request, Design $design): JsonResponse
    {
        [$link, $token] = $this->collab->createLink($request->user(), $design, $request->input('role'), $request->input('expires_at'));

        return response()->json(['link' => $this->collab->presentLink($link, $token)], 201);
    }

    public function revokeLink(Request $request, Design $design, int $link): JsonResponse
    {
        return response()->json(['link' => $this->collab->presentLink($this->collab->revokeLink($request->user(), $design, $link))]);
    }

    public function comments(Request $request, Design $design): JsonResponse
    {
        return response()->json(['comments' => $this->commentRows($request, $design)]);
    }

    /** Comentarios con lo que el usuario actual puede hacer con cada uno (resolver, borrar). */
    private function commentRows(Request $request, Design $design): array
    {
        $user = $request->user();
        $role = $this->collab->role($user, $design);
        $rows = $this->collab->comments($design);
        $roots = collect($rows)->keyBy('id');

        return array_map(function (array $comment) use ($user, $role, $roots) {
            $root = $comment['parent_id'] === null ? $comment : ($roots[$comment['parent_id']] ?? $comment);

            return $comment + ['can_resolve' => in_array($role, ['owner', 'editor'], true) || ($role === 'commenter' && (int) $root['author_id'] === (int) $user->id),
                'can_delete' => $role === 'owner' || (in_array($role, ['editor', 'commenter'], true) && (int) $comment['author_id'] === (int) $user->id)];
        }, $rows);
    }

    public function addComment(Request $request, Design $design): JsonResponse
    {
        $user = $request->user();
        $this->collab->authorize($user, $design, 'comment');
        $comment = $this->collab->addComment($design, $user, null, $request->only(['body', 'page_id', 'element_id', 'parent_id']));

        return response()->json(['comment' => collect($this->commentRows($request, $design))->firstWhere('id', $comment->id)], 201);
    }

    public function resolve(Request $request, Design $design, int $comment): JsonResponse
    {
        $this->collab->setResolved($request->user(), $design, $comment, true);

        return response()->json(['resolved' => true]);
    }

    public function reopen(Request $request, Design $design, int $comment): JsonResponse
    {
        $this->collab->setResolved($request->user(), $design, $comment, false);

        return response()->json(['resolved' => false]);
    }

    public function deleteComment(Request $request, Design $design, int $comment): JsonResponse
    {
        $this->collab->deleteComment($request->user(), $design, $comment);

        return response()->json(['deleted' => true]);
    }

    public function copy(Request $request, Design $design): JsonResponse
    {
        $copy = $this->studio->saveCopy($request->user(), $design, $request->only(['name', 'document']));

        return response()->json(['design' => $this->studio->present($request->user(), $copy, $request->is('api/*')),
            'edit_url' => route('studio.designs.edit', $copy, absolute: false)], 201);
    }

    public function versions(Request $request, Design $design): JsonResponse
    {
        return response()->json(['versions' => $this->studio->versions($request->user(), $design), 'revision' => $design->revision]);
    }

    public function restoreVersion(Request $request, Design $design, int $version): JsonResponse
    {
        $restored = $this->studio->restoreVersion($request->user(), $design, $version, $request->input('revision'));

        return response()->json(['design' => $this->studio->present($request->user(), $restored, $request->is('api/*'))]);
    }
}
