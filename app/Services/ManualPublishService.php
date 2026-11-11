<?php

namespace App\Services;

use App\Enums\PostStatus;
use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\User;
use App\Notifications\PostWorkflowActivity;
use App\Support\TargetMedia;
use Illuminate\Support\Facades\DB;

/**
 * Publicacion manual (T-19): un destino marcado `manual` NUNCA se envia a la red. Al llegar la hora programada se avisa una sola vez
 * (`manual_notified_at`, claim atomico) con el contenido listo para copiar y la media descargable; el usuario lo publica por su cuenta
 * y lo marca como publicado, con una URL opcional https. Mientras tanto el destino sigue `programada` (el calendario lo muestra como
 * «pendiente manual»).
 */
class ManualPublishService
{
    public function __construct(private readonly PostService $posts) {}

    /** Avisa de los destinos manuales vencidos y aprobados que aun no se han avisado. Idempotente: dos pasadas no duplican el aviso. */
    public function notifyDue(): int
    {
        $due = PostTarget::withoutUserScope()
            ->where('manual', true)->where('status', PostStatus::Scheduled->value)->whereNull('manual_notified_at')
            ->whereIn('post_id', Post::withoutUserScope()->where('scheduled_at', '<=', now())->where(fn ($q) => $q->whereNull('approval_status')->orWhere('approval_status', Post::APPROVAL_APPROVED))->select('id'))
            ->orderBy('id')->limit(500)->pluck('id');

        $claimed = [];
        foreach ($due as $id) {
            // El UPDATE condicionado es el claim: solo una pasada lo consigue.
            $won = PostTarget::withoutUserScope()->whereKey($id)->where('manual', true)->whereNull('manual_notified_at')
                ->where('status', PostStatus::Scheduled->value)->update(['manual_notified_at' => now()]);
            if ($won === 1) {
                $claimed[(int) PostTarget::withoutUserScope()->whereKey($id)->value('post_id')][] = (int) $id;
            }
        }

        foreach ($claimed as $postId => $targetIds) {
            $post = Post::withoutUserScope()->find($postId);
            $owner = $post ? User::query()->find($post->user_id) : null;
            if ($post === null || $owner === null) {
                continue;
            }
            $brand = Brand::withoutUserScope()->find($post->brand_id);
            $labels = PostTarget::withoutUserScope()->whereIn('id', $targetIds)->get()->map(fn (PostTarget $t) => $t->network->label())->unique()->implode(', ');
            $owner->notify(new PostWorkflowActivity(
                'manual_due', $post->id, $post->brand_id, (string) $brand?->name,
                'Toca publicar a mano en '.$labels.' la publicacion de «'.$brand?->name.'». Abre el aviso para copiar el texto y descargar la media.',
                (string) $post->text,
            ));
        }

        return array_sum(array_map('count', $claimed));
    }

    /**
     * Contenido listo para copiar de los destinos manuales del post.
     *
     * @return list<array<string, mixed>>
     */
    public function content(User $actor, Post $post): array
    {
        abort_unless((int) $post->user_id === (int) $actor->id, 404);
        $post->loadMissing(['targets.account', 'media']);
        $common = $post->media->pluck('id')->all();
        $assets = $post->media->keyBy('id');

        return $post->targets->filter(fn (PostTarget $t) => $t->manual)->map(function (PostTarget $t) use ($post, $common, $assets) {
            $media = collect(TargetMedia::effective($t, $common))->map(function ($m) use ($assets) {
                $id = (int) (is_array($m) ? $m['id'] : $m);
                $asset = $assets->get($id) ?? MediaAsset::withoutUserScope()->find($id);

                return $asset ? ['id' => $id, 'name' => $asset->original_name ?? ('media-'.$id), 'alt' => is_array($m) ? ($m['alt'] ?? null) : null, 'download_url' => route('media.file', $id, false)] : null;
            })->filter()->values()->all();

            return [
                'target_id' => $t->id,
                'network' => $t->network->value,
                'network_label' => $t->network->label(),
                'account_name' => $t->account?->name,
                'content_type' => $t->content_type->value,
                'text' => $t->effectiveText() ?? $post->text,
                'first_comment' => $t->first_comment,
                'media' => $media,
                'status' => $t->status->value,
                'published' => $t->status === PostStatus::Published,
                'external_url' => $t->external_url,
                'notified' => $t->manual_notified_at !== null,
            ];
        })->values()->all();
    }

    /**
     * El usuario confirma que ya publico a mano. Idempotente: un destino ya publicado se devuelve tal cual. `url` es opcional y debe ser https.
     */
    public function markPublished(User $actor, Post $post, PostTarget $target, mixed $url = null): PostTarget
    {
        abort_unless((int) $post->user_id === (int) $actor->id && (int) $target->post_id === (int) $post->id, 404);

        $url = is_string($url) ? trim($url) : $url;
        $url = $url === '' ? null : $url;
        if ($url !== null) {
            if (! is_string($url) || mb_strlen($url) > 2000 || ! preg_match('#^https://[^\s/]+\.[^\s/]+(/\S*)?$#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new PostValidationException(['url' => ['La URL debe empezar por https:// y ser valida.']]);
            }
        }

        return DB::transaction(function () use ($post, $target, $url): PostTarget {
            $locked = PostTarget::withoutUserScope()->lockForUpdate()->findOrFail($target->id);
            if (! $locked->manual) {
                throw new PostValidationException(['target' => ['Este destino no es de publicacion manual.']]);
            }
            if ($locked->status === PostStatus::Published) {
                return $locked; // idempotente
            }
            if ($locked->status !== PostStatus::Scheduled) {
                throw new PostValidationException(['target' => ['Solo se puede marcar como publicado un destino programado.']]);
            }
            if (! Post::withoutUserScope()->findOrFail($post->id)->isPublishable()) {
                throw new PostValidationException(['approval' => ['La publicacion requiere aprobacion antes de publicarse.']]);
            }

            $this->posts->transition($locked, PostStatus::Publishing, ['attempts' => $locked->attempts + 1]);

            return $this->posts->transition($locked->refresh(), PostStatus::Published, ['external_url' => $url, 'status_note' => 'Publicada manualmente.']);
        });
    }
}
