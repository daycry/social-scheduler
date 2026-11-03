<?php

namespace App\Services\Studio;

use App\Exceptions\PostValidationException;
use App\Models\Brand;
use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\Studio\Design;
use App\Models\Studio\DesignExport;
use App\Models\Studio\DesignRevision;
use App\Models\User;
use App\Services\PostService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudioExports
{
    public function __construct(private readonly StudioService $studio, private readonly StudioAssets $assets, private readonly PostService $posts) {}

    /** Register files already processed by MediaUploadService; a browser declaration alone cannot mark a new asset ready. */
    public function register(User $user, Design $design, array $data): DesignExport
    {
        $design = $this->studio->owned($user, $design);
        $data = Validator::make($data, [
            'revision' => ['required', 'integer', 'min:1'],
            'format' => ['required', Rule::in(['png', 'jpeg', 'webp'])],
            'pages' => ['required', 'array', 'min:1', 'max:100'], 'pages.*' => ['required', 'string', 'max:64', 'distinct'],
            'media_ids' => ['required', 'array', 'min:1', 'max:100'], 'media_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'scale' => ['sometimes', 'numeric', 'min:0.1', 'max:4'], 'transparent' => ['sometimes', 'boolean'], 'quality' => ['sometimes', 'numeric', 'min:0.1', 'max:1'],
        ])->validate();
        $revision = $this->revision($user, $design, (int) $data['revision']);
        $pages = $data['pages'];
        $ids = array_map('intval', $data['media_ids']);
        $pageIds = array_column($revision->document['pages'], 'id');
        if (! array_is_list($pages) || ! array_is_list($ids) || count($pages) !== count($ids) || array_diff($pages, $pageIds) !== []) {
            throw ValidationException::withMessages(['pages' => ['Selecciona páginas existentes y un archivo por página, en el mismo orden.']]);
        }
        $scale = (float) ($data['scale'] ?? 1);
        $width = (int) round($revision->document['width'] * $scale);
        $height = (int) round($revision->document['height'] * $scale);
        if ($width * $height > 64000000 || $width > 16000 || $height > 16000) {
            throw ValidationException::withMessages(['scale' => ['La exportación supera el tamaño seguro de 64 megapíxeles y 16000 píxeles por lado.']]);
        }
        $mime = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'][$data['format']];
        $media = MediaAsset::withoutUserScope()->where('user_id', $user->id)->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $asset = $media->get($id);
            if (! $asset || ! $asset->isReady() || $asset->kind !== 'image' || $asset->mime !== $mime || $asset->width !== $width || $asset->height !== $height) {
                throw ValidationException::withMessages(['media_ids' => ['Cada archivo debe ser propio, estar listo y coincidir con el formato y dimensiones de la revisión exportada.']]);
            }
        }
        $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($design->brand_id);

        return DB::transaction(function () use ($user, $brand, $design, $data, $pages, $ids): DesignExport {
            foreach ($ids as $id) {
                $this->assets->attach($user, $brand, $id);
            }

            return DesignExport::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'design_id' => $design->id,
                'revision' => (int) $data['revision'], 'format' => $data['format'], 'pages' => $pages, 'media_ids' => $ids,
                'options' => ['scale' => (float) ($data['scale'] ?? 1), 'transparent' => (bool) ($data['transparent'] ?? false), 'quality' => (float) ($data['quality'] ?? 1)], 'status' => 'ready']);
        });
    }

    public function list(User $user, Design $design): Collection
    {
        $design = $this->studio->owned($user, $design);

        return DesignExport::withoutUserScope()->where('user_id', $user->id)->where('design_id', $design->id)->latest('id')->get();
    }

    public function owned(User $user, DesignExport $export): DesignExport
    {
        $current = DesignExport::withoutUserScope()->where('user_id', $user->id)->findOrFail($export->id);
        abort_unless(Brand::withoutUserScope()->where('user_id', $user->id)->whereKey($current->brand_id)->exists(), 404);

        return $current;
    }

    public function paginated(User $user, Design $design, int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        $design = $this->studio->owned($user, $design);
        Validator::make(['page' => $page, 'per_page' => $perPage], ['page' => ['integer', 'min:1'], 'per_page' => ['integer', 'min:1', 'max:100']])->validate();

        return DesignExport::withoutUserScope()->where('user_id', $user->id)->where('design_id', $design->id)
            ->latest('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function pageMetadata(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
            'total' => $page->total(), 'has_more' => $page->hasMorePages()];
    }

    public function revision(User $user, Design $design, int $revision): DesignRevision
    {
        $design = $this->studio->owned($user, $design);
        $snapshot = DesignRevision::withoutUserScope()->where('user_id', $user->id)->where('design_id', $design->id)->where('revision', $revision)->first();
        if (! $snapshot) {
            throw ValidationException::withMessages(['revision' => ['La revisión indicada no existe en este diseño.']]);
        }

        return $snapshot;
    }

    public function present(User $user, DesignExport $export): array
    {
        $current = $this->owned($user, $export);

        return $this->serialize($user, $current);
    }

    /** Validate the collection once; callers must never re-query every row during history polling. */
    public function presentMany(User $user, Collection $exports): array
    {
        abort_if($exports->contains(fn (DesignExport $export) => (int) $export->user_id !== (int) $user->id), 404);
        $brands = $exports->pluck('brand_id')->unique()->values();
        if ($brands->isNotEmpty()) {
            abort_unless(Brand::withoutUserScope()->where('user_id', $user->id)->whereIn('id', $brands)->count() === $brands->count(), 404);
        }

        return $exports->map(fn (DesignExport $export) => $this->serialize($user, $export))->all();
    }

    private function serialize(User $user, DesignExport $current): array
    {

        return $current->only(['id', 'brand_id', 'design_id', 'revision', 'format', 'pages', 'media_ids', 'options', 'status', 'error', 'created_at', 'updated_at']) + [
            'download_url' => $current->status === 'ready' && $current->disk === 'local' && $current->path
                ? URL::temporarySignedRoute('studio.exports.download', now()->addMinutes(10), ['export' => $current->id, 'owner' => $user->id]) : null,
        ];
    }

    public function createPost(User $user, DesignExport $export, array $data = []): Post
    {
        $export = $this->owned($user, $export);
        if ($export->status !== 'ready' || empty($export->media_ids) || ! in_array($export->format, ['png', 'jpeg', 'webp', 'mp4', 'gif'], true)) {
            throw ValidationException::withMessages(['export' => ['La exportación todavía no contiene archivos publicables listos.']]);
        }
        $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($export->brand_id);
        foreach ($export->media_ids as $id) {
            $this->assets->owned($user, $brand, (int) $id);
        }
        $data = Validator::make($data, ['text' => ['nullable', 'string', 'max:100000'], 'targets' => ['sometimes', 'array']])->validate();
        try {
            return $this->posts->create($user, $brand, ['text' => $data['text'] ?? null, 'schedule' => false, 'media' => $export->media_ids, 'targets' => $data['targets'] ?? []]);
        } catch (PostValidationException $e) {
            throw ValidationException::withMessages($e->errors());
        }
    }
}
