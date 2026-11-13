<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidPostTransition;
use App\Exceptions\PostStateConflict;
use App\Exceptions\PostValidationException;
use App\Services\AuditLog;
use App\Services\PostWorkflowActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Aprobaciones, publicacion manual, reintento, series, lotes y colaboradores de marca (T-19). Las MISMAS acciones sirven a la web
 * (sesion) y a la API REST (Sanctum); `PostWorkflowActions` es compartido con el MCP. La autorizacion vive en los servicios
 * (propietario o aprobador), nunca en la ruta: un post ajeno responde 404 igual que uno inexistente.
 */
class PostWorkflowController extends Controller
{
    public function __construct(private readonly PostWorkflowActions $actions) {}

    public function pending(Request $request): JsonResponse
    {
        return $this->guard($request, 'list_pending_approvals', null, fn () => $this->actions->pending($request->user()));
    }

    public function requestApproval(Request $request, int $post): JsonResponse
    {
        return $this->guard($request, 'request_post_approval', $post, fn () => $this->actions->requestApproval($request->user(), $post));
    }

    public function approve(Request $request, int $post): JsonResponse
    {
        return $this->guard($request, 'approve_post', $post, fn () => $this->actions->approve($request->user(), $post, $request->input('signature')));
    }

    public function reject(Request $request, int $post): JsonResponse
    {
        return $this->guard($request, 'reject_post', $post, fn () => $this->actions->reject($request->user(), $post, $request->input('comment'), $request->input('signature')));
    }

    public function manualContent(Request $request, int $post): JsonResponse
    {
        return $this->guard($request, 'manual_content', $post, fn () => $this->actions->manualContent($request->user(), $post));
    }

    public function markPublished(Request $request, int $post, int $target): JsonResponse
    {
        return $this->guard($request, 'mark_post_published', $post, fn () => $this->actions->markPublished($request->user(), $post, $target, $request->input('url')));
    }

    public function retry(Request $request, int $post): JsonResponse
    {
        return $this->guard($request, 'retry_post', $post, fn () => $this->actions->retry($request->user(), $post));
    }

    public function batch(Request $request): JsonResponse
    {
        // Aprobar o rechazar en lote exige la ability propia en los tokens de la API (las sesiones web no llevan token).
        if ($request->routeIs('api.*') && in_array($request->input('action'), ['approve', 'reject'], true) && ! $request->user()->tokenCan('posts:approve')) {
            return response()->json(['message' => 'Falta el permiso posts:approve.'], 403);
        }
        $key = $request->header('Idempotency-Key') ?: $request->input('idempotency_key');
        $replayed = false;
        $response = $this->guard($request, 'batch_posts', null, function () use ($request, $key, &$replayed) {
            $run = $this->actions->batch($request->user(), $request->all(), is_string($key) ? trim($key) : null);
            $replayed = $run['replayed'];

            return $run['result'] + ['replayed' => $run['replayed']];
        });

        return $response->header('Idempotency-Replayed', $replayed ? 'true' : 'false');
    }

    public function seriesIndex(Request $request): JsonResponse
    {
        return $this->guard($request, 'list_series', null, fn () => $this->actions->seriesIndex($request->user()));
    }

    public function seriesShow(Request $request, int $series): JsonResponse
    {
        return $this->guard($request, 'get_series', null, fn () => $this->actions->seriesShow($request->user(), $series));
    }

    /** En la API la marca va en la ruta (`brands/{brand}/series`) y manda sobre cualquier `brand_id` del cuerpo; la web la envia en el cuerpo. */
    public function seriesStore(Request $request, ?int $brand = null): JsonResponse
    {
        $input = $request->all();
        if ($brand !== null) {
            $input['brand_id'] = $brand;
        }

        return $this->guard($request, 'create_series', null, fn () => $this->actions->seriesStore($request->user(), $input), 201);
    }

    public function seriesUpdate(Request $request, int $series): JsonResponse
    {
        return $this->guard($request, 'update_series', null, fn () => $this->actions->seriesUpdate($request->user(), $series, $request->all()));
    }

    public function seriesCancel(Request $request, int $series): JsonResponse
    {
        return $this->guard($request, 'cancel_series', null, fn () => $this->actions->seriesCancel($request->user(), $series));
    }

    public function collaborators(Request $request, int $brand): JsonResponse
    {
        return $this->guard($request, 'list_collaborators', null, fn () => $this->actions->collaborators($request->user(), $brand));
    }

    public function invite(Request $request, int $brand): JsonResponse
    {
        return $this->guard($request, 'invite_collaborator', null, fn () => $this->actions->invite($request->user(), $brand, $request->input('email'), $request->input('role')), 202);
    }

    public function revoke(Request $request, int $brand, int $collaborator): JsonResponse
    {
        return $this->guard($request, 'revoke_collaborator', null, fn () => $this->actions->revoke($request->user(), $brand, $collaborator));
    }

    /**
     * Ejecuta la accion, traduce los errores de dominio a HTTP y audita el canal API.
     *
     * @param  callable(): array<string, mixed>  $run
     */
    private function guard(Request $request, string $action, ?int $postId, callable $run, int $status = 200): JsonResponse
    {
        try {
            $result = $run();
        } catch (PostStateConflict $e) {
            $this->audit($request, $action, AuditLog::ERROR, $postId);

            return response()->json(['message' => $e->getMessage()], 409);
        } catch (PostValidationException $e) {
            $this->audit($request, $action, AuditLog::ERROR, $postId);

            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (InvalidPostTransition $e) {
            $this->audit($request, $action, AuditLog::ERROR, $postId);

            return response()->json(['message' => $e->getMessage()], 409);
        } catch (HttpExceptionInterface $e) {
            $this->audit($request, $action, $e->getStatusCode() === 404 ? AuditLog::DENIED : AuditLog::ERROR, $postId);

            throw $e;
        }
        $this->audit($request, $action, AuditLog::OK, $postId);

        return response()->json($result, $status);
    }

    private function audit(Request $request, string $action, string $outcome, ?int $postId): void
    {
        if ($request->routeIs('api.*')) {
            app(AuditLog::class)->record($request->user(), $action, $outcome, 'post', $postId, null, 'api');
        }
    }
}
