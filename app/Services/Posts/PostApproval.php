<?php

namespace App\Services\Posts;

use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Post;
use App\Models\PostTarget;
use App\Notifications\PostWorkflowActivity;
use App\Services\BrandCollaboration;
use Illuminate\Support\Facades\DB;

/**
 * Aprobacion de publicaciones (T-19) y retencion de las ocurrencias de series con aprobacion (T-32): reglas sobre el plan, estado
 * pendiente con su huella, aviso a los aprobadores (agrupado dentro de una tanda) y la retencion/liberacion/programacion de ocurrencias.
 */
class PostApproval
{
    public function __construct(
        private readonly PostSignature $signature,
        private readonly PostTransitions $transitions,
        private readonly BrandCollaboration $collaboration,
    ) {}

    /**
     * Reglas de aprobacion sobre el plan: un post sin aprobar no se programa; editar el contenido de uno aprobado lo devuelve a pendiente.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function applyRules(Post $post, array $plan): array
    {
        if ($post->approval_status === null) {
            return $plan;
        }

        // Cambiar el contenido invalida la version que se pidio aprobar (CWE-367), este aprobada o aun pendiente.
        if (in_array($post->approval_status, [Post::APPROVAL_APPROVED, Post::APPROVAL_PENDING], true) && $this->signature->ofPost($post) !== $this->signature->ofPlan($plan)) {
            $plan['status'] = PostStatus::Draft;
            $plan['publish_now'] = false;
            $plan['reset_approval'] = true;

            return $plan;
        }

        if ($plan['status'] === PostStatus::Scheduled && ! $post->isPublishable()) {
            throw new PostValidationException(['approval' => ['La publicacion requiere aprobacion antes de programarse.']]);
        }

        return $plan;
    }

    /** @var list<int>|null ids de los posts que han pedido aprobacion dentro de una tanda (`collectApprovalRequests`) */
    private ?array $approvalBatch = null;

    /**
     * Ejecuta `$run` sin avisar a los aprobadores post a post y devuelve los ids que han pedido aprobacion, para que el llamador emita UN
     * aviso agrupado (una serie diaria no genera decenas de avisos de golpe). No se anida: dentro de otra tanda solo ejecuta.
     *
     * @return list<int>
     */
    public function collect(callable $run): array
    {
        if ($this->approvalBatch !== null) {
            $run();

            return [];
        }
        $this->approvalBatch = [];
        try {
            $run();

            return array_values(array_unique($this->approvalBatch));
        } finally {
            $this->approvalBatch = null;
        }
    }

    /**
     * Una ocurrencia de serie ya aprobada pasa a `programada` en su fecha SIN editarla (`update()` la sacaria de la serie). Idempotente;
     * solo si la fecha sigue siendo futura y todos sus destinos estan en borrador. Devuelve si programo algo.
     */
    public function scheduleApproved(Post $post): bool
    {
        return DB::transaction(function () use ($post): bool {
            $locked = Post::withoutUserScope()->lockForUpdate()->find($post->id);
            if ($locked === null || $locked->series_id === null || ! $locked->isPublishable() || $locked->scheduled_at === null || $locked->scheduled_at->lte(now())) {
                return false;
            }

            return $this->scheduleDraftTargets($locked);
        });
    }

    /**
     * Activar `requires_approval` en una serie retiene sus ocurrencias futuras aun no publicadas: sus destinos vuelven a borrador y la
     * ocurrencia queda pendiente de aprobacion (nada la publica hasta que se apruebe). Devuelve si cambio algo.
     */
    public function hold(Post $post): bool
    {
        return DB::transaction(function () use ($post): bool {
            $locked = Post::withoutUserScope()->lockForUpdate()->find($post->id);
            if ($locked === null || $locked->approval_status !== null || $locked->scheduled_at === null || $locked->scheduled_at->lte(now())) {
                return false;
            }
            $targets = PostTarget::withoutUserScope()->where('post_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            if ($targets->contains(fn (PostTarget $t) => $t->status->isInFlight() || $t->status->isFinal())) {
                return false; // ya salio o esta saliendo: no se retiene
            }
            foreach ($targets->where('status', PostStatus::Scheduled) as $target) {
                $this->transitions->apply($target, PostStatus::Draft, $this->transitions->freshAttempt($target, $this->transitions->effectiveText($target, $locked->text), $locked->scheduled_at));
            }
            $locked->syncStatus();
            $this->markPending($locked->fresh());

            return true;
        });
    }

    /** Desactivar `requires_approval`: las ocurrencias pendientes (no las rechazadas) dejan de necesitarla y se programan. */
    public function release(Post $post): bool
    {
        return DB::transaction(function () use ($post): bool {
            $locked = Post::withoutUserScope()->lockForUpdate()->find($post->id);
            if ($locked === null || $locked->approval_status !== Post::APPROVAL_PENDING) {
                return false;
            }
            $locked->forceFill(['approval_status' => null, 'approval_comment' => null, 'approval_requested_at' => null, 'approval_decided_at' => null, 'approval_decided_by' => null, 'approval_signature' => null])->save();
            if ($locked->scheduled_at !== null && $locked->scheduled_at->gt(now())) {
                $this->scheduleDraftTargets($locked);
            }

            return true;
        });
    }

    /** Pasa a `programada` los destinos en borrador del post (ya bloqueado) y recalcula su estado. */
    private function scheduleDraftTargets(Post $locked): bool
    {
        $changed = false;
        foreach (PostTarget::withoutUserScope()->where('post_id', $locked->id)->orderBy('id')->lockForUpdate()->get() as $target) {
            if ($target->status === PostStatus::Draft) {
                $this->transitions->apply($target, PostStatus::Scheduled, $this->transitions->freshAttempt($target, $this->transitions->effectiveText($target, $locked->text), $locked->scheduled_at) + ['manual_notified_at' => null, 'manual_reminded_at' => null]);
                $changed = true;
            }
        }
        if ($changed) {
            $locked->syncStatus();
        }

        return $changed;
    }

    public function markPending(Post $post, bool $edited = false): void
    {
        $post->forceFill([
            'approval_status' => Post::APPROVAL_PENDING, 'approval_comment' => null, 'approval_requested_at' => now(),
            'approval_decided_at' => null, 'approval_decided_by' => null, 'approval_signature' => $this->signature->ofPost($post),
        ])->save();

        if ($this->approvalBatch !== null) {
            $this->approvalBatch[] = (int) $post->id; // el aviso lo emite quien abrio la tanda, agrupado

            return;
        }

        $brandName = (string) Brand::withoutUserScope()->whereKey($post->brand_id)->value('name');
        $message = $edited ? 'Una publicacion aprobada de «'.$brandName.'» se ha editado y vuelve a necesitar tu aprobacion.' : 'Hay una publicacion de «'.$brandName.'» que pide tu aprobacion.';
        foreach ($this->collaboration->approvers((int) $post->brand_id) as $approver) {
            $approver->notify(new PostWorkflowActivity('approval_requested', $post->id, $post->brand_id, $brandName, $message, (string) $post->text));
        }
    }
}
