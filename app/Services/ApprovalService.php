<?php

namespace App\Services;

use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\Post;
use App\Models\Studio\Design;
use App\Models\User;
use App\Notifications\PostWorkflowActivity;
use App\Notifications\StudioDesignActivity;
use App\Services\Studio\StudioCollab;
use App\Support\Ownership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aprobaciones (T-19). Flujo: `pendiente` -> `aprobado` -> (el propietario programa) -> publicada, o `rechazado` con comentario ->
 * el post sigue en borrador y se puede editar y volver a pedir. Aprueba el propietario o un colaborador de la marca con rol aprobador.
 * Un post con `approval_status` distinto de nulo/`aprobado` NO se programa (PostService) ni se publica (job y despachador).
 * Las decisiones son idempotentes: repetirlas sobre un post ya decidido igual no cambia nada.
 */
class ApprovalService
{
    public function __construct(private readonly PostService $posts, private readonly BrandCollaboration $collab, private readonly StudioCollab $studio) {}

    public function canDecide(User $user, Post $post): bool
    {
        return Ownership::matches($post, $user) || $this->collab->isApprover($user, (int) $post->brand_id);
    }

    /** Post al que el usuario tiene acceso como propietario o aprobador; 404 uniforme si no. */
    public function findForDecision(User $user, mixed $id): Post
    {
        $post = is_numeric($id) ? Post::withoutUserScope()->find((int) $id) : null;
        Ownership::ensure($post !== null && $this->canDecide($user, $post));

        return $post;
    }

    /** Pide la aprobacion (solo el propietario). Un post programado vuelve a borrador: no se publica hasta que se apruebe. */
    public function request(User $actor, Post $post): Post
    {
        Ownership::assert($post, $actor);

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
        Ownership::ensure($this->canDecide($actor, $post));

        return $this->decide($actor, $post, Post::APPROVAL_APPROVED, null, $signature);
    }

    public function reject(User $actor, Post $post, mixed $comment, mixed $signature = null): Post
    {
        Ownership::ensure($this->canDecide($actor, $post));

        return $this->decide($actor, $post, Post::APPROVAL_REJECTED, $this->rejectionComment($comment), $signature);
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

    public function requestDesign(User $actor, Design $design): Design
    {
        Ownership::assert($design, $actor);

        [$pending, $changed] = DB::transaction(function () use ($actor, $design): array {
            $locked = Design::ownedBy($actor->id)->lockForUpdate()->findOrFail($design->id);
            if ($locked->approval_status === Post::APPROVAL_PENDING && $locked->approvalIsCurrent()) {
                return [$locked, false];
            }
            $locked->forceFill([
                'approval_status' => Post::APPROVAL_PENDING, 'approval_comment' => null, 'approval_requested_at' => now(),
                'approval_decided_at' => null, 'approval_decided_by' => null, 'approval_signature' => Str::random(40),
                'approval_revision' => $locked->revision,
            ])->save();

            return [$locked, true];
        });
        if ($changed) {
            $notice = new StudioDesignActivity('approval_requested', $pending->id, $pending->name, $actor->name.' pide tu aprobación para «'.$pending->name.'».');
            foreach ($this->collab->approvers((int) $pending->brand_id, (int) $pending->user_id) as $approver) {
                $approver->notify($notice);
            }
        }

        return $pending;
    }

    public function decideDesign(User $actor, Design $design, string $status, mixed $comment, mixed $signature): Design
    {
        [$decided, $changed] = DB::transaction(function () use ($actor, $design, $status, $comment, $signature): array {
            $locked = Design::withoutUserScope()->lockForUpdate()->findOrFail($design->id);
            $this->studio->authorize($actor, $locked, 'approve');
            $comment = $status === Post::APPROVAL_REJECTED ? $this->rejectionComment($comment) : null;
            abort_if($locked->approval_revision !== null && ! $locked->approvalIsCurrent(), 409, 'El diseño ha cambiado. Pide la aprobación de la versión actual.');
            if ($locked->approval_status === $status) {
                $this->assertSignature($locked->approval_signature, $signature, 'el diseño');

                return [$locked, false];
            }
            $this->assertPending($locked->approval_status, 'El diseño no está pendiente de aprobación.');
            $this->assertSignature($locked->approval_signature, $signature, 'el diseño');
            $locked->forceFill(['approval_status' => $status, 'approval_comment' => $comment, 'approval_decided_at' => now(), 'approval_decided_by' => $actor->id])->save();

            return [$locked, true];
        });
        if ($changed && ! Ownership::matches($decided, $actor)) {
            $approved = $status === Post::APPROVAL_APPROVED;
            User::query()->find($decided->user_id)?->notify(new StudioDesignActivity(
                $approved ? 'approval_approved' : 'approval_rejected',
                $decided->id,
                $decided->name,
                $actor->name.($approved ? ' ha aprobado «'.$decided->name.'».' : ' ha rechazado «'.$decided->name.'»: '.mb_substr($decided->approval_comment, 0, 240)),
            ));
        }

        return $decided;
    }

    /** @return array{pending: list<array<string, mixed>>, mine: list<array<string, mixed>>} */
    public function inbox(User $user): array
    {
        $designs = Design::withoutUserScope()->select([
            'id', 'user_id', 'brand_id', 'name', 'revision', 'approval_status', 'approval_revision',
            'approval_requested_at', 'approval_decided_at', 'approval_comment', 'approval_signature',
        ])->with(['brand:id,name', 'user:id,name']);
        $pendingDesigns = (clone $designs)
            ->where(fn (Builder $query) => $query->where('user_id', $user->id)->orWhereExists(Design::brandApproverGrant($user->id)->selectRaw('1')))
            ->where('approval_status', Post::APPROVAL_PENDING)->whereColumn('approval_revision', 'revision')
            ->orderBy('approval_requested_at')->orderBy('id')->limit(200)->get()->all();
        $myDesigns = $designs->ownedBy($user)
            ->whereIn('approval_status', [Post::APPROVAL_REJECTED, Post::APPROVAL_APPROVED])
            ->latest('approval_decided_at')->orderByDesc('id')->limit(30)->get()->all();
        $myPosts = $this->queryFor($user)->where('user_id', $user->id)
            ->whereIn('approval_status', [Post::APPROVAL_REJECTED, Post::APPROVAL_APPROVED])
            ->latest('approval_decided_at')->orderByDesc('id')->limit(30)->get()->all();

        return [
            'pending' => $this->inboxRows([...$this->pendingFor($user), ...$pendingDesigns], 'approval_requested_at', 'asc', 200),
            'mine' => $this->inboxRows([...$myPosts, ...$myDesigns], 'approval_decided_at', 'desc', 30),
        ];
    }

    /**
     * @param  list<Post|Design>  $items
     * @return list<array<string, mixed>>
     */
    private function inboxRows(array $items, string $date, string $direction, int $limit): array
    {
        return collect($items)->sortBy([[$date, $direction], ['id', $direction]])->take($limit)
            ->map(fn (Post|Design $item) => $item instanceof Design ? $this->presentDesign($item) : $this->present($item))
            ->values()->all();
    }

    /** @return array<string, mixed> */
    public function presentDesign(Design $design): array
    {
        $design->loadMissing(['brand:id,name', 'user:id,name']);

        return [
            'kind' => 'design',
            'id' => $design->id,
            'brand_id' => $design->brand_id,
            'brand_name' => $design->brand?->name,
            'text' => $design->name,
            'approval_revision' => $design->approval_revision,
            'approval_current' => $design->approvalIsCurrent(),
            'approval_status' => $design->approval_status,
            'approval_comment' => $design->approval_comment,
            // A request token distinguishes successive requests for the same revision.
            'content_signature' => $design->approval_signature,
            'requested_by' => (string) $design->user?->name,
        ];
    }

    private function rejectionComment(mixed $value): string
    {
        $comment = is_scalar($value) ? trim((string) $value) : '';
        if ($comment === '') {
            throw new PostValidationException(['comment' => ['Indica el motivo del rechazo.']]);
        }
        if (mb_strlen($comment) > 2000) {
            throw new PostValidationException(['comment' => ['El comentario admite 2000 caracteres como maximo.']]);
        }

        return $comment;
    }

    private function assertPending(?string $current, string $message): void
    {
        if ($current !== Post::APPROVAL_PENDING) {
            throw new PostValidationException(['approval' => [$message]]);
        }
    }

    private function assertSignature(?string $expected, mixed $signature, string $resource): void
    {
        if (! is_string($signature) || $signature === '') {
            throw new PostValidationException(['signature' => ['Indica la versión que has revisado (signature, la content_signature de la solicitud).']]);
        }
        if (! hash_equals((string) $expected, $signature)) {
            abort(409, "El contenido ha cambiado desde que lo revisaste: vuelve a abrir {$resource} antes de decidir.");
        }
    }

    private function decide(User $actor, Post $post, string $status, ?string $comment, mixed $signature = null): Post
    {
        $changed = DB::transaction(function () use ($actor, $post, $status, $comment, $signature): bool {
            $locked = Post::withoutUserScope()->lockForUpdate()->findOrFail($post->id);
            if ($locked->approval_status === $status) {
                return false; // idempotente: ya esta decidido igual
            }
            $this->assertPending($locked->approval_status, 'La publicacion no esta pendiente de aprobacion.');
            $this->assertSignature($locked->approval_signature, $signature, 'la publicacion');
            $locked->forceFill(['approval_status' => $status, 'approval_comment' => $comment, 'approval_decided_at' => now(), 'approval_decided_by' => $actor->id])->save();

            return true;
        });
        $post = $post->fresh();

        // Una ocurrencia de serie aprobada sale en su fecha: se programa aqui, sin `update()` (que la sacaria de la serie).
        if ($changed && $status === Post::APPROVAL_APPROVED && $post->series_id !== null) {
            $this->posts->scheduleApprovedOccurrence($post);
            $post = $post->fresh();
        }

        if ($changed && ! Ownership::matches($post, $actor)) {
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
