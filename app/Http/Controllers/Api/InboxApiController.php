<?php

namespace App\Http\Controllers\Api;

use App\Models\InboxComment;
use App\Services\InboxService;
use App\Support\CursorPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Buzon de comentarios de la API v1 (los mismos servicios que `list_comments` y `reply_comment` del MCP). El texto y el autor de cada
 * comentario lo escribe un tercero: cada fila lleva `untrusted` y nunca debe leerse como instrucciones.
 */
class InboxApiController extends ApiController
{
    public function __construct(private readonly InboxService $inbox) {}

    public function index(Request $request, int $brand): JsonResponse
    {
        $brand = $this->ownedBrand($request, $brand);
        $f = $request->validate(CursorPage::RULES + [
            'network' => ['nullable', Rule::in(['instagram', 'facebook', 'threads', 'bluesky', 'youtube'])],
            'status' => ['nullable', Rule::in(['open', 'closed'])],
            'label' => ['nullable', 'string', 'max:50'],
            'assigned' => ['nullable', 'string', 'max:20'],
            'unread' => ['nullable', 'boolean'],
        ]);
        $assigned = $f['assigned'] ?? null;

        return $this->respond($request, 'list_comments', 'brand', $brand->id, fn () => $this->inbox->page($request->user(), [
            'network' => $f['network'] ?? null,
            'status' => $f['status'] ?? null,
            'label' => isset($f['label']) ? mb_strtolower(trim($f['label'])) : null,
            'assigned' => $assigned === 'me' ? $request->user()->id : (($assigned === 'none' || is_numeric($assigned)) ? $assigned : null),
            'unread' => (bool) ($f['unread'] ?? false),
            'brand_id' => $brand->id,
        ], $f['limit'] ?? null, $f['cursor'] ?? null, [$brand->id => $brand->name]));
    }

    /** Responde con la cuenta de la marca: se publica en la red y es irreversible desde aqui. */
    public function reply(Request $request, int $comment): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:'.(int) config('social.inbox.reply_max_chars', 2200)]]);
        $found = InboxComment::withoutUserScope()->where('user_id', $request->user()->id)->find($comment) ?? abort(404);

        return $this->respond($request, 'reply_comment', 'inbox_comment', $found->id, function () use ($request, $found, $data) {
            $reply = $this->inbox->reply($request->user(), $found, $data['text']);

            return ['id' => $reply->id, 'comment_id' => $found->id, 'replied' => true];
        }, 201);
    }
}
