<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\Studio\BrandKit;
use App\Models\Studio\Design;
use App\Models\Studio\DesignFolder;
use App\Models\Studio\DesignRevision;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StudioService
{
    public function __construct(private readonly StudioDocument $documents, private readonly StudioAssets $assets) {}

    public function designs(User $user, Brand $brand, array $filters = []): LengthAwarePaginator
    {
        $this->assets->assertBrand($user, $brand);
        $query = Design::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)
            ->select(['id', 'user_id', 'brand_id', 'folder_id', 'name', 'revision', 'is_template', 'width', 'height', 'page_count', 'created_at', 'updated_at', 'deleted_at']);
        if (filter_var($filters['archived'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $query->onlyTrashed();
        }
        if (isset($filters['query']) && trim((string) $filters['query']) !== '') {
            $term = mb_substr(trim((string) $filters['query']), 0, 120);
            $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%']);
        }
        if (isset($filters['folder_id'])) {
            $this->folderId($user, $brand, $filters['folder_id']);
            $query->where('folder_id', $filters['folder_id']);
        }
        if (array_key_exists('templates', $filters) && $filters['templates'] !== null) {
            $query->where('is_template', filter_var($filters['templates'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->latest('updated_at')->latest('id')->paginate(24, ['*'], 'page', isset($filters['page']) ? (int) $filters['page'] : null)->withQueryString();
    }

    public function create(User $user, Brand $brand, array $data, ?array $kitReference = null): Design
    {
        $this->assets->assertBrand($user, $brand);
        $data = $this->input($data);
        $document = $this->documents->validate($user, $brand, $data['document']);
        $folderId = $this->folderId($user, $brand, $data['folder_id'] ?? null);

        $kitReference ??= array_intersect_key($this->kit($user, $brand), array_flip(['palette', 'fonts', 'logos']));
        $kitReference = self::reference($kitReference);

        return DB::transaction(function () use ($user, $brand, $data, $document, $folderId, $kitReference): Design {
            $design = Design::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => trim($data['name']), 'document' => $document,
                'width' => $document['width'], 'height' => $document['height'], 'page_count' => count($document['pages']),
                'revision' => 1, 'is_template' => $data['is_template'] ?? false, 'folder_id' => $folderId, 'kit_reference' => $kitReference]);
            $this->snapshot($design);

            return $design;
        });
    }

    public function update(User $user, Design $design, array $data, ?array $kitReference = null): Design
    {
        $current = $this->owned($user, $design);
        $data = $this->input($data, true);
        $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($current->brand_id);
        $document = $this->documents->validate($user, $brand, $data['document']);
        $folderId = $this->folderId($user, $brand, array_key_exists('folder_id', $data) ? $data['folder_id'] : $current->folder_id);

        return DB::transaction(function () use ($user, $current, $data, $document, $folderId, $kitReference): Design {
            $locked = Design::withoutUserScope()->where('user_id', $user->id)->lockForUpdate()->findOrFail($current->id);
            abort_if($locked->revision !== (int) $data['revision'], 409, 'El diseño cambió en otra sesión. Recarga la versión guardada antes de continuar.');
            if ($kitReference !== null) {
                $locked->kit_reference = self::reference($kitReference);
            } elseif (isset($data['kit_revision'])) {
                $snapshot = DesignRevision::withoutUserScope()->where('user_id', $user->id)
                    ->where('design_id', $locked->id)->where('revision', $data['kit_revision'])->first();
                if ($snapshot === null) {
                    throw ValidationException::withMessages(['kit_revision' => ['Selecciona una revisión existente de este mismo diseño.']]);
                }
                $locked->kit_reference = self::reference($snapshot->kit_reference);
            }
            $locked->fill(['name' => trim($data['name']), 'document' => $document, 'revision' => $locked->revision + 1,
                'width' => $document['width'], 'height' => $document['height'], 'page_count' => count($document['pages']),
                'is_template' => $data['is_template'] ?? $locked->is_template, 'folder_id' => $folderId])->save();
            $this->snapshot($locked);

            return $locked;
        });
    }

    public function duplicate(User $user, Design $design, ?string $name = null): Design
    {
        $source = $this->owned($user, $design);
        $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($source->brand_id);

        return $this->create($user, $brand, ['name' => $name ?? mb_substr($source->name, 0, 248).' (copia)', 'document' => $source->document,
            'folder_id' => $source->folder_id, 'is_template' => $source->is_template], $source->kit_reference ?? []);
    }

    public function archive(User $user, Design $design): void
    {
        $this->owned($user, $design)->delete();
    }

    public function restore(User $user, Design $design): Design
    {
        $current = $this->owned($user, $design, true);
        $current->restore();

        return $current;
    }

    public function owned(User $user, Design $design, bool $withTrashed = false): Design
    {
        $query = Design::withoutUserScope()->where('user_id', $user->id);
        if ($withTrashed) {
            $query->withTrashed();
        }
        $current = $query->findOrFail($design->id);
        abort_unless(Brand::withoutUserScope()->where('user_id', $user->id)->whereKey($current->brand_id)->exists(), 404);

        return $current;
    }

    public function present(User $user, Design $design): array
    {
        $current = $this->owned($user, $design, $design->trashed());

        $data = $current->only(['id', 'user_id', 'brand_id', 'folder_id', 'name', 'document', 'revision', 'is_template', 'kit_reference', 'created_at', 'updated_at', 'deleted_at']);
        if ($data['kit_reference'] !== null) {
            $data['kit_reference'] = self::reference($data['kit_reference']);
        }

        return $data + ['template_fields' => StudioAutomation::fields($current->document)];
    }

    /** Preserve legacy meaning without recapturing a newer brand kit or serializing an empty list. */
    public static function reference(?array $reference): array
    {
        return ['palette' => $reference['palette'] ?? [], 'fonts' => $reference['fonts'] ?? [], 'logos' => $reference['logos'] ?? []];
    }

    public function folders(User $user, Brand $brand): Collection
    {
        $this->assets->assertBrand($user, $brand);

        return DesignFolder::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->orderBy('name')->get();
    }

    public function createFolder(User $user, Brand $brand, string $name): DesignFolder
    {
        $this->assets->assertBrand($user, $brand);
        $name = trim($name);
        Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:255']])->validate();

        return DesignFolder::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => $name]);
    }

    public function deleteFolder(User $user, DesignFolder $folder): void
    {
        $owned = DesignFolder::withoutUserScope()->where('user_id', $user->id)->findOrFail($folder->id);
        DB::transaction(function () use ($owned): void {
            Design::withoutUserScope()->withTrashed()->where('user_id', $owned->user_id)->where('folder_id', $owned->id)->update(['folder_id' => null]);
            $owned->delete();
        });
    }

    public function kit(User $user, Brand $brand): array
    {
        $this->assets->assertBrand($user, $brand);
        $kit = BrandKit::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->first();

        return $kit?->only(['palette', 'fonts', 'logos', 'guidelines', 'voice']) ?? ['palette' => ['#7c3aed', '#111827', '#ffffff'], 'fonts' => ['Arial'], 'logos' => [], 'guidelines' => '', 'voice' => ''];
    }

    public function updateKit(User $user, Brand $brand, array $data): array
    {
        $this->assets->assertBrand($user, $brand);
        $data = Validator::make($data, ['palette' => ['sometimes', 'array', 'max:50'], 'palette.*' => ['required', 'string'],
            'fonts' => ['sometimes', 'array', 'max:50'], 'fonts.*' => ['required', 'string', 'max:100'],
            'logos' => ['sometimes', 'array', 'max:30'], 'logos.*' => ['required', 'integer', 'min:1', 'distinct'],
            'guidelines' => ['sometimes', 'nullable', 'string', 'max:10000'], 'voice' => ['sometimes', 'nullable', 'string', 'max:5000']])->validate();
        foreach ($data['palette'] ?? [] as $color) {
            if (! StudioDocument::color($color)) {
                throw ValidationException::withMessages(['palette' => ['Los colores deben ser hexadecimales.']]);
            }
        }
        foreach ($data['fonts'] ?? [] as $font) {
            if (! StudioDocument::validFont($font)) {
                throw ValidationException::withMessages(['fonts' => ['Indica familias tipográficas sin CSS ni URLs.']]);
            }
        }
        foreach ($data['logos'] ?? [] as $mediaId) {
            try {
                $asset = $this->assets->owned($user, $brand, (int) $mediaId);
            } catch (ModelNotFoundException) {
                throw ValidationException::withMessages(['logos' => ['Los logos deben estar registrados en esta marca.']]);
            }
            if ($asset->kind !== 'image') {
                throw ValidationException::withMessages(['logos' => ['Los logos deben ser imágenes.']]);
            }
        }
        BrandKit::withoutUserScope()->updateOrCreate(['user_id' => $user->id, 'brand_id' => $brand->id], array_replace($this->kit($user, $brand), $data));

        return $this->kit($user, $brand);
    }

    private function input(array $data, bool $updating = false): array
    {
        $rules = ['name' => ['required', 'string', 'max:255'], 'document' => ['required', 'array'], 'folder_id' => ['nullable', 'integer', 'min:1'], 'is_template' => ['sometimes', 'boolean']];
        if ($updating) {
            $rules['revision'] = ['required', 'integer', 'min:1'];
            $rules['kit_revision'] = ['sometimes', 'integer', 'min:1'];
        }
        $data = Validator::make($data, $rules)->validate();
        if (trim($data['name']) === '') {
            throw ValidationException::withMessages(['name' => ['El nombre es obligatorio.']]);
        }

        return $data;
    }

    private function folderId(User $user, Brand $brand, mixed $id): ?int
    {
        if ($id === null || $id === '') {
            return null;
        }
        if (! DesignFolder::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->whereKey($id)->exists()) {
            throw ValidationException::withMessages(['folder_id' => ['La carpeta no pertenece a esta marca.']]);
        }

        return (int) $id;
    }

    private function snapshot(Design $design): void
    {
        DesignRevision::create(['user_id' => $design->user_id, 'design_id' => $design->id, 'revision' => $design->revision, 'name' => $design->name, 'document' => $design->document, 'kit_reference' => $design->kit_reference]);
    }
}
