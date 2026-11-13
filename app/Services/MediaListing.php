<?php

namespace App\Services;

use App\Models\MediaAsset;
use App\Models\User;
use App\Support\CursorPage;
use InvalidArgumentException;

/** Listado de la media de un usuario con el contrato de cursor comun: lo comparten la web, la API v1 y el MCP. */
class MediaListing
{
    public const STATUSES = [MediaAsset::STATUS_READY, MediaAsset::STATUS_PENDING, MediaAsset::STATUS_INVALID, 'todos'];

    /** Filtro por tipo. Sin él se listan solo los publicables (imagen y video); `pdf` es la biblioteca de documentos de Canva, que no se publica en ninguna red. */
    public const KINDS = [MediaAsset::KIND_IMAGE, MediaAsset::KIND_VIDEO, MediaAsset::KIND_PDF];

    /**
     * @return array{media: list<array<string, mixed>>, has_more: bool, next_cursor: ?string}
     *
     * @throws InvalidArgumentException si `status` no es uno de STATUSES o `kind` no es uno de KINDS
     */
    public function page(User $user, ?string $status = null, mixed $limit = null, mixed $cursor = null, bool $web = false, ?string $kind = null): array
    {
        $status ??= MediaAsset::STATUS_READY;
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('status debe ser listo, pendiente, invalido o todos.');
        }

        if ($kind !== null && ! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('kind debe ser image, video o pdf.');
        }

        $query = MediaAsset::withoutUserScope()->where('user_id', $user->id)->whereIn('kind', $kind === null ? [MediaAsset::KIND_IMAGE, MediaAsset::KIND_VIDEO] : [$kind]);
        if ($status !== 'todos') {
            $query->where('status', $status);
        }
        [$rows, $page] = CursorPage::fetch($query->latest('id'), $limit, $cursor, 'media:u'.$user->id);

        return ['media' => $rows->map(fn (MediaAsset $m) => self::present($m, $web))->values()->all()] + $page;
    }

    /**
     * Forma de un fichero. Las URLs de vista previa y descarga son de la web (piden la sesion del navegador): no sirven a un cliente de la API
     * ni del MCP, que no las reciben.
     *
     * @return array<string, mixed>
     */
    public static function present(MediaAsset $asset, bool $web = false): array
    {
        return $web ? $asset->toPublicArray() : array_diff_key($asset->toPublicArray(), ['preview_url' => true, 'download_url' => true]);
    }
}
