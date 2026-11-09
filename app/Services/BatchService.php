<?php

namespace App\Services;

use App\Exceptions\InvalidPostTransition;
use App\Exceptions\PostValidationException;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Acciones masivas sobre publicaciones (T-19): programar, reprogramar (desplazando dias u horas), cancelar, borrar, aprobar y rechazar.
 * Cada elemento se autoriza y se ejecuta por separado, con el mismo servicio que la accion individual, y el resultado es
 * un `ok`/`error` por elemento: un fallo no detiene el lote. Un id inexistente y uno ajeno dan la misma respuesta (sin IDOR).
 */
class BatchService
{
    public const ACTIONS = ['schedule', 'reschedule', 'cancel', 'delete', 'approve', 'reject'];

    public const MAX_ITEMS = 100;

    public function __construct(private readonly PostService $posts, private readonly ApprovalService $approvals) {}

    /**
     * @param  array<string, mixed>  $input  action, post_ids, shift_days, shift_hours, comment
     * @return array{action: string, total: int, ok: int, failed: int, results: list<array<string, mixed>>}
     */
    public function run(User $actor, array $input): array
    {
        $data = Validator::make($input, [
            'action' => ['required', 'in:'.implode(',', self::ACTIONS)],
            'post_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ITEMS],
            'post_ids.*' => ['integer', 'min:1'],
            'shift_days' => ['nullable', 'integer', 'between:-365,365'],
            'shift_hours' => ['nullable', 'integer', 'between:-8760,8760'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ], ['action.in' => 'La accion debe ser: '.implode(', ', self::ACTIONS).'.', 'post_ids.max' => 'Un lote admite como maximo '.self::MAX_ITEMS.' publicaciones.'])->validate();

        $action = $data['action'];
        $days = (int) ($data['shift_days'] ?? 0);
        $hours = (int) ($data['shift_hours'] ?? 0);
        if ($action === 'reschedule' && $days === 0 && $hours === 0) {
            throw new PostValidationException(['shift_days' => ['Indica cuantos dias u horas desplazar.']]);
        }
        if ($action === 'reject' && trim((string) ($data['comment'] ?? '')) === '') {
            throw new PostValidationException(['comment' => ['Indica el motivo del rechazo.']]);
        }

        $results = [];
        foreach (array_values(array_unique(array_map('intval', $data['post_ids']))) as $id) {
            $results[] = $this->one($actor, $action, $id, $days, $hours, $data['comment'] ?? null);
        }
        $ok = count(array_filter($results, fn ($r) => $r['ok']));

        return ['action' => $action, 'total' => count($results), 'ok' => $ok, 'failed' => count($results) - $ok, 'results' => $results];
    }

    /** @return array<string, mixed> */
    private function one(User $actor, string $action, int $id, int $days, int $hours, ?string $comment): array
    {
        $post = Post::withoutUserScope()->find($id);
        $decision = in_array($action, ['approve', 'reject'], true);
        $allowed = $post !== null && ($decision ? $this->approvals->canDecide($actor, $post) : (int) $post->user_id === (int) $actor->id);
        if (! $allowed) {
            return ['post_id' => $id, 'ok' => false, 'error' => 'Publicacion no encontrada.'];
        }

        try {
            $result = match ($action) {
                'schedule' => $this->posts->schedule($actor, $post),
                'reschedule' => $this->shift($actor, $post, $days, $hours),
                'cancel' => $this->posts->unschedule($actor, $post),
                'delete' => $this->delete($actor, $post),
                'approve' => $this->approvals->approve($actor, $post),
                'reject' => $this->approvals->reject($actor, $post, $comment),
            };
        } catch (PostValidationException|InvalidPostTransition $e) {
            return ['post_id' => $id, 'ok' => false, 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return ['post_id' => $id, 'ok' => false, 'error' => 'No se pudo completar la accion.'];
        }

        return ['post_id' => $id, 'ok' => true, 'status' => $result?->status->value ?? 'eliminada', 'approval_status' => $result?->approval_status];
    }

    private function shift(User $actor, Post $post, int $days, int $hours): Post
    {
        if ($post->scheduled_at === null) {
            throw new PostValidationException(['scheduled_at' => ['La publicacion no tiene fecha que desplazar.']]);
        }

        return $this->posts->reschedule($actor, $post, $post->scheduled_at->copy()->addDays($days)->addHours($hours));
    }

    private function delete(User $actor, Post $post): ?Post
    {
        $this->posts->delete($actor, $post);

        return null;
    }
}
