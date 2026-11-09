<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\Post;
use App\Models\PostSeries;
use App\Models\PostTarget;
use App\Models\User;

/**
 * Acciones del flujo de publicacion (T-19) con UNA sola forma de entrada y de salida: web, API REST y MCP las llaman aqui, asi
 * que la paridad es estructural (misma autorizacion, mismos servicios, mismo JSON). Un recurso ajeno lanza 404 como uno inexistente.
 */
class PostWorkflowActions
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly PostService $posts,
        private readonly PostPresenter $presenter,
        private readonly ManualPublishService $manual,
        private readonly SeriesService $series,
        private readonly BatchService $batches,
        private readonly IdempotencyService $idempotency,
        private readonly BrandCollaboration $collab,
    ) {}

    /** @return array<string, mixed> */
    public function pending(User $user): array
    {
        return ['pending' => array_map(fn (Post $p) => $this->approvals->present($p), $this->approvals->pendingFor($user))];
    }

    /** @return array<string, mixed> */
    public function requestApproval(User $user, int $post): array
    {
        return $this->decision($this->approvals->request($user, $this->owned($user, $post)));
    }

    /** @return array<string, mixed> */
    public function approve(User $user, int $post): array
    {
        return $this->decision($this->approvals->approve($user, $this->approvals->findForDecision($user, $post)));
    }

    /** @return array<string, mixed> */
    public function reject(User $user, int $post, mixed $comment): array
    {
        return $this->decision($this->approvals->reject($user, $this->approvals->findForDecision($user, $post), $comment));
    }

    /** @return array<string, mixed> */
    public function manualContent(User $user, int $post): array
    {
        $model = $this->owned($user, $post);

        return ['post_id' => $model->id, 'targets' => $this->manual->content($user, $model)];
    }

    /** @return array<string, mixed> */
    public function markPublished(User $user, int $post, int $target, mixed $url): array
    {
        $model = $this->owned($user, $post);
        $row = PostTarget::withoutUserScope()->where('post_id', $model->id)->find($target);
        abort_if($row === null, 404);
        $done = $this->manual->markPublished($user, $model, $row, $url);

        return ['post' => $this->presenter->present($model->fresh()), 'target' => ['id' => $done->id, 'status' => $done->status->value, 'external_url' => $done->external_url]];
    }

    /** @return array<string, mixed> */
    public function retry(User $user, int $post): array
    {
        $result = $this->posts->retryFailed($user, $this->owned($user, $post));

        return ['post' => $this->presenter->present($result['post']), 'retried' => $result['retried'], 'skipped' => $result['skipped']];
    }

    /**
     * @param  array<string, mixed>  $input  action, post_ids, shift_days, shift_hours, comment
     * @return array{result: array<string, mixed>, replayed: bool}
     */
    public function batch(User $user, array $input, ?string $key): array
    {
        $input = array_intersect_key($input, array_flip(['action', 'post_ids', 'shift_days', 'shift_hours', 'comment']));

        return $this->idempotency->run($user, 'post-batch', $key, $input, fn () => $this->batches->run($user, $input));
    }

    /** @return array<string, mixed> */
    public function seriesIndex(User $user): array
    {
        return ['series' => PostSeries::withoutUserScope()->where('user_id', $user->id)->orderByDesc('id')->limit(100)->get()
            ->map(fn (PostSeries $s) => $this->series->present($s))->all()];
    }

    /** @return array<string, mixed> */
    public function seriesShow(User $user, int $series): array
    {
        return ['series' => $this->series->present($this->ownedSeries($user, $series))];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function seriesStore(User $user, array $input): array
    {
        return ['series' => $this->series->present($this->series->create($user, $this->ownedBrand($user, (int) ($input['brand_id'] ?? 0)), $input))];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function seriesUpdate(User $user, int $series, array $input): array
    {
        return ['series' => $this->series->present($this->series->update($user, $this->ownedSeries($user, $series), $input))];
    }

    /** @return array<string, mixed> */
    public function seriesCancel(User $user, int $series): array
    {
        return ['series' => $this->series->present($this->series->cancel($user, $this->ownedSeries($user, $series)))];
    }

    /** @return array<string, mixed> */
    public function collaborators(User $user, int $brand): array
    {
        return ['collaborators' => $this->collab->list($user, $this->ownedBrand($user, $brand))];
    }

    /** @return array<string, mixed> respuesta uniforme: no revela si el email existe */
    public function invite(User $user, int $brand, mixed $email, mixed $role): array
    {
        $this->collab->invite($user, $this->ownedBrand($user, $brand), $email, $role);

        return ['message' => BrandCollaboration::INVITE_MESSAGE];
    }

    /** @return array<string, mixed> */
    public function revoke(User $user, int $brand, int $collaborator): array
    {
        $this->collab->revoke($user, $this->ownedBrand($user, $brand), $collaborator);

        return ['revoked' => true];
    }

    // ------------------------------------------------------------------ internals

    /** @return array<string, mixed> */
    private function decision(Post $post): array
    {
        $fresh = $post->fresh(['brand', 'targets', 'media']);

        return ['post' => $this->approvals->present($fresh) + ['status' => $fresh->status->value]];
    }

    private function owned(User $user, int $post): Post
    {
        $model = Post::withoutUserScope()->where('user_id', $user->id)->find($post);
        abort_if($model === null, 404);

        return $model;
    }

    private function ownedSeries(User $user, int $series): PostSeries
    {
        $model = PostSeries::withoutUserScope()->where('user_id', $user->id)->find($series);
        abort_if($model === null, 404);

        return $model;
    }

    private function ownedBrand(User $user, int $brand): Brand
    {
        $model = Brand::withoutUserScope()->where('user_id', $user->id)->find($brand);
        abort_if($model === null, 404);

        return $model;
    }
}
