<?php

namespace App\Services;

use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Post;
use App\Models\User;
use App\Notifications\PostWorkflowActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Aprobaciones (T-19). Flujo: `pendiente` -> `aprobado` -> (el propietario programa) -> publicada, o `rechazado` con comentario ->
 * el post sigue en borrador y se puede editar y volver a pedir. Aprueba el propietario o un colaborador de la marca con rol aprobador.
 * Un post con `approval_status` distinto de nulo/`aprobado` NO se programa (PostService) ni se publica (job y despachador).
 * Las decisiones son idempotentes: repetirlas sobre un post ya decidido igual no cambia nada.
 */
class ApprovalService
{
    public function __construct(private readonly PostService $posts, private readonly BrandCollaboration $collab) {}

    public function canDecide(User $user, Post $post): bool
    {
        return (int) $post->user_id === (int) $user->id || $this->collab->isApprover($user, (int) $post->brand_id);
    }

    /** Post al que el usuario tiene acceso como propietario o aprobador; 404 uniforme si no. */
    public function findForDecision(User $user, mixed $id): Post
    {
        $post = is_numeric($id) ? Post::withoutUserScope()->find((int) $id) : null;
        abort_if($post === null || ! $this->canDecide($user, $post), 404);

        return $post;
    }

    /** Pide la aprobacion (solo el propietario). Un post programado vuelve a borrador: no se publica hasta que se apruebe. */
    public function request(User $actor, Post $post): Post
    {
        abort_unless((int) $post->user_id === (int) $actor->id, 404);

        if ($post->approval_status === Post::APPROVAL_PENDING) {
            return $post->fresh(); // idempotente
        }

        DB::transaction(function () use ($actor, $post) {
            $locked = Post::withoutUserScope()->lockForUpdate()->findOrFail($post->id);
            if ($locked->status->isInFlight() || $locked->status->isFinal() || $locked->targets()->get()->contains(fn ($t) => $t->status->isFinal() || $t->status->isInFlight())) {
                throw new PostValidationException(['approval' => ['La publicacion ya se ha publicado o se esta publicando.']]);
            }
            if ($locked->status === PostStatus::Scheduled) {
                $this->posts->unschedule($actor, $locked);
            }
            Post::withoutUserScope()->whereKey($post->id)->update([
                'approval_status' => Post::APPROVAL_PENDING, 'approval_comment' => null, 'approval_requested_at' => now(),
                'approval_decided_at' => null, 'approval_decided_by' => null, 'approval_signature' => $this->posts->contentSignature($locked),
            ]);
        });
        $post = $post->fresh();

        foreach ($this->collab->approvers((int) $post->brand_id) as $approver) {
            $approver->notify($this->notice('approval_requested', $post, $actor->name.' pide tu aprobacion para una publicacion de «'.$this->brandName($post).'».'));
        }

        return $post;
    }

    public function approve(User $actor, Post $post, mixed $signature = null): Post
    {
        abort_unless($this->canDecide($actor, $post), 404);

        return $this->decide($actor, $post, Post::APPROVAL_APPROVED, null, $signature);
    }

    public function reject(User $actor, Post $post, mixed $comment, mixed $signature = null): Post
    {
        abort_unless($this->canDecide($actor, $post), 404);
        $comment = trim((string) $comment);
        if ($comment === '') {
            throw new PostValidationException(['comment' => ['Indica el motivo del rechazo.']]);
        }
        if (mb_strlen($comment) > 2000) {
            throw new PostValidationException(['comment' => ['El comentario admite 2000 caracteres como maximo.']]);
        }

        return $this->decide($actor, $post, Post::APPROVAL_REJECTED, $comment, $signature);
    }

    /**
     * Pendientes de decidir que el usuario puede ver: las suyas y las de las marcas donde es aprobador.
     *
     * @return list<Post>
     */
    public function pendingFor(User $user): array
    {
        return $this->queryFor($user)->where('approval_status', Post::APPROVAL_PENDING)->orderBy('approval_requested_at')->orderBy('id')->limit(200)->get()->all();
    }

    /** @return Builder<Post> */
    public function queryFor(User $user): Builder
    {
        $brands = $this->collab->approverBrandIds($user);

        return Post::withoutUserScope()->with(['brand', 'targets', 'media', 'user:id,name'])->where(fn ($q) => $q->where('user_id', $user->id)->when($brands !== [], fn ($q) => $q->orWhereIn('brand_id', $brands)));
    }

    /** @return array<string, mixed> */
    public function present(Post $post): array
    {
        $post->loadMissing(['brand', 'targets', 'media', 'user:id,name']);
        $tz = $post->brand?->timezone ?: 'UTC';

        return [
            'id' => $post->id,
            'brand_id' => $post->brand_id,
            'brand_name' => $post->brand?->name,
            'text' => $post->text,
            'approval_status' => $post->approval_status,
            'approval_comment' => $post->approval_comment,
            // Version del contenido que se pide aprobar: aprobar o rechazar exige devolverla (CWE-367).
            'content_signature' => $post->approval_signature,
            'requested_by' => (string) $post->user?->name,
            'scheduled_at_local' => $post->scheduled_at?->copy()->setTimezone($tz)->format('Y-m-d\TH:i'),
            'networks' => $post->targets->map(fn ($t) => $t->network->label())->unique()->values()->all(),
            'media_count' => $post->media->count(),
        ];
    }

    private function decide(User $actor, Post $post, string $status, ?string $comment, mixed $signature = null): Post
    {
        $changed = DB::transaction(function () use ($actor, $post, $status, $comment, $signature): bool {
            $locked = Post::withoutUserScope()->lockForUpdate()->findOrFail($post->id);
            if ($locked->approval_status === $status) {
                return false; // idempotente: ya esta decidido igual
            }
            if ($locked->approval_status !== Post::APPROVAL_PENDING) {
                throw new PostValidationException(['approval' => ['La publicacion no esta pendiente de aprobacion.']]);
            }
            if (! is_string($signature) || $signature === '') {
                throw new PostValidationException(['signature' => ['Indica la version que has revisado (signature, la content_signature de la publicacion).']]);
            }
            if (! hash_equals((string) $locked->approval_signature, $signature)) {
                abort(409, 'El contenido ha cambiado desde que lo revisaste: vuelve a abrir la publicacion antes de decidir.');
            }
            $locked->forceFill(['approval_status' => $status, 'approval_comment' => $comment, 'approval_decided_at' => now(), 'approval_decided_by' => $actor->id])->save();

            return true;
        });
        $post = $post->fresh();

        if ($changed && (int) $post->user_id !== (int) $actor->id) {
            $owner = User::query()->find($post->user_id);
            $owner?->notify($this->notice(
                $status === Post::APPROVAL_APPROVED ? 'approval_approved' : 'approval_rejected',
                $post,
                $status === Post::APPROVAL_APPROVED
                    ? $actor->name.' ha aprobado tu publicacion. Ya puedes programarla.'
                    : $actor->name.' ha rechazado tu publicacion: '.$comment,
            ));
        }

        return $post;
    }

    private function notice(string $kind, Post $post, string $message): PostWorkflowActivity
    {
        return new PostWorkflowActivity($kind, $post->id, $post->brand_id, $this->brandName($post), $message, (string) $post->text);
    }

    private function brandName(Post $post): string
    {
        return (string) Brand::withoutUserScope()->whereKey($post->brand_id)->value('name');
    }
}
