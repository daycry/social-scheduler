<?php

namespace App\Services\Studio;

use App\Enums\PostStatus;
use App\Exceptions\PostStateConflict;
use App\Exceptions\PostValidationException;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Studio\DesignExport;
use App\Models\User;
use App\Services\Posts\PostMediaProvenance;
use App\Services\Posts\PostPlanner;
use App\Services\Posts\PostTransitions;
use App\Services\PostService;

class StudioPostMedia
{
    private const ALREADY_PUBLISHING = 'La publicación ya se ha publicado o se está publicando. La exportación se guardó en la biblioteca.';

    public function __construct(
        private readonly PostService $posts,
        private readonly PostPlanner $planner,
        private readonly PostTransitions $transitions,
    ) {}

    /** Actualiza la publicación en la misma transacción que bloquea la exportación. */
    public function replace(User $user, DesignExport $export, Post $post): Post
    {
        // La llamada ya bloqueó la exportación. Se mantiene el orden de edición: destinos y después publicación.
        $targets = $this->transitions->lockEditable($post, self::ALREADY_PUBLISHING);
        $post = Post::ownedBy($user->id)->where('brand_id', $export->brand_id)->lockForUpdate()->findOrFail($post->id);
        if ($post->status->isFinal() || $post->status->isInFlight()) {
            throw new PostStateConflict(self::ALREADY_PUBLISHING);
        }

        $previous = $post->designExport()->where('user_id', $user->id)->where('brand_id', $export->brand_id)
            ->where('design_id', $export->design_id)->first();
        if ($previous === null) {
            throw new PostValidationException(['post' => ['La publicación ya no está vinculada a una exportación de este diseño.']]);
        }
        if ($export->id === $previous->id) {
            return $post;
        }
        // El id aumenta al crear una exportación; completar o reintentar un render no cambia ese orden.
        if ($export->id < $previous->id) {
            throw new PostValidationException(['post' => ['La publicación ya contiene una exportación más reciente de este diseño.']]);
        }

        $previousIds = array_map('intval', $previous->media_ids ?? []);
        $exportIds = array_map('intval', $export->media_ids);
        $pageFiles = $this->matchingPages($previous, $export, $previousIds, $exportIds);
        $targets = $targets->values()->load('mediaItems');
        $data = $this->planner->dataFromPost($post, [
            'schedule' => $targets->contains(fn (PostTarget $target) => $target->status === PostStatus::Scheduled),
            'scheduled_at' => $post->scheduled_at,
        ], $targets);
        $previousCount = count($previousIds);
        $common = array_map(fn ($id) => ['id' => (int) $id], $data['media']);
        $commonLinkedIds = $post->media()->wherePivot('design_export_id', $previous->id)->pluck('media_assets.id')
            ->map(fn ($id) => (int) $id)->all();
        $updatedCommon = $this->replaceList($common, $commonLinkedIds, $previousCount, $exportIds, $pageFiles);
        $data['media'] = array_column($updatedCommon['items'], 'id');
        $targetLinks = [];
        foreach ($data['targets'] as $index => $target) {
            if ($target['media'] !== null) {
                $linkedIds = $targets[$index]->mediaItems->where('design_export_id', $previous->id)
                    ->pluck('media_asset_id')->map(fn ($id) => (int) $id)->all();
                $updated = $this->replaceList($target['media'], $linkedIds, $previousCount, $exportIds, $pageFiles);
                $data['targets'][$index]['media'] = $updated['items'];
                $targetLinks[$target['account_id'].'|'.$target['content_type']] = $updated['linked'];
            }
        }

        return $this->posts->update($user, $post, $data, new PostMediaProvenance($export, $updatedCommon['linked'], $targetLinks));
    }

    /** @return array<int, int>|null Correspondencias por página. null indica un vídeo combinado sin correspondencia. */
    private function matchingPages(DesignExport $previous, DesignExport $export, array $previousIds, array $exportIds): ?array
    {
        if (count($previousIds) === 1 && count($exportIds) === 1 && $previous->pages === $export->pages) {
            return [$previousIds[0] => $exportIds[0]];
        }
        if (count($previous->pages ?? []) !== count($previousIds) || count($export->pages ?? []) !== count($exportIds)) {
            return null;
        }
        $newByPage = array_combine($export->pages, $exportIds);
        $matching = [];
        foreach ($previous->pages as $index => $page) {
            if (isset($newByPage[$page])) {
                $matching[$previousIds[$index]] = $newByPage[$page];
            }
        }

        return $matching;
    }

    /**
     * @param  list<array{id: int, alt?: ?string, cover_id?: ?int}>  $items
     * @param  list<int>  $linkedIds
     * @param  list<int>  $exportIds
     * @param  array<int, int>|null  $pageFiles
     * @return array{items: list<array{id: int, alt?: ?string, cover_id?: ?int}>, linked: list<int>}
     */
    private function replaceList(array $items, array $linkedIds, int $previousCount, array $exportIds, ?array $pageFiles): array
    {
        if ($linkedIds === []) {
            return ['items' => $items, 'linked' => []];
        }

        $replaceWhole = count($linkedIds) === $previousCount;
        if (! $replaceWhole && array_diff($linkedIds, array_keys($pageFiles ?? [])) !== []) {
            throw new PostValidationException(['post' => ['La nueva exportación no incluye alguna página adjunta. Vuelve a exportarla o cambia el adjunto desde el compositor.']]);
        }
        $links = array_fill_keys($linkedIds, true);
        $independent = array_diff_key(array_fill_keys(array_column($items, 'id'), true), $links);

        // Las descripciones y portadas siguen a su página, aunque el nuevo archivo ocupe otra posición.
        $itemsByNewId = [];
        foreach ($items as $item) {
            if (! isset($links[$item['id']])) {
                continue;
            }
            if ($pageFiles === null && (isset($item['cover_id']) || filled($item['alt'] ?? null))) {
                throw new PostValidationException(['post' => ['La nueva exportación no conserva la correspondencia de páginas. Cambia los adjuntos desde el compositor para revisar sus descripciones y portadas.']]);
            }
            $newId = $pageFiles[$item['id']] ?? null;
            if ($newId !== null && ! isset($independent[$newId])) {
                $itemsByNewId[$newId] = ['id' => $newId] + $item;
            }
        }
        // Una lista completa adopta el nuevo orden del diseño. Una parcial conserva las páginas elegidas.
        $newFiles = array_values(array_filter($exportIds, fn (int $id) => ! isset($independent[$id])));
        $replacementOrder = $replaceWhole ? $newFiles : array_keys($itemsByNewId);
        $result = [];
        $next = 0;
        $remaining = count($linkedIds);
        foreach ($items as $item) {
            if (! isset($links[$item['id']])) {
                $result[] = $item;

                continue;
            }
            $newId = $replaceWhole ? ($replacementOrder[$next++] ?? null) : ($pageFiles[$item['id']] ?? null);
            if ($newId !== null && ! isset($independent[$newId])) {
                $result[] = $itemsByNewId[$newId] ?? ['id' => $newId];
            }
            // Las páginas nuevas se insertan junto al último archivo sustituido, antes de los adjuntos posteriores.
            if ($replaceWhole && --$remaining === 0) {
                foreach (array_slice($replacementOrder, $next) as $extraId) {
                    $result[] = $itemsByNewId[$extraId] ?? ['id' => $extraId];
                }
            }
        }

        return ['items' => $result, 'linked' => $replacementOrder];
    }
}
