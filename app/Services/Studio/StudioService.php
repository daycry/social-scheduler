<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\Studio\BrandKit;
use App\Models\Studio\Design;
use App\Models\Studio\DesignFolder;
use App\Models\Studio\DesignRevision;
use App\Models\Studio\DesignShare;
use App\Models\Studio\StudioFont;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StudioService
{
    public function __construct(private readonly StudioDocument $documents, private readonly StudioAssets $assets, private readonly StudioCollab $collab) {}

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

        $kitReference ??= array_intersect_key($this->kit($user, $brand), array_flip(['palette', 'fonts', 'font_ids', 'logos']));
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
        $actor = $user;
        $user = $this->collab->authorize($actor, $design, 'edit');
        $current = $this->owned($user, $design);
        $data = $this->input($data, true);
        if ($actor->id !== $user->id) {
            // Carpeta y condición de plantilla son decisiones del propietario: un editor invitado no las cambia ni las ve.
            unset($data['folder_id'], $data['is_template']);
        }
        $brand = Brand::withoutUserScope()->where('user_id', $user->id)->findOrFail($current->brand_id);
        $document = $this->documents->validate($user, $brand, $data['document']);
        $folderId = $this->folderId($user, $brand, array_key_exists('folder_id', $data) ? $data['folder_id'] : $current->folder_id);

        $coalesce = ($data['autosave'] ?? false) === true && $kitReference === null && ! isset($data['kit_revision']);

        return DB::transaction(function () use ($user, $actor, $current, $data, $document, $folderId, $kitReference, $coalesce): Design {
            $locked = Design::withoutUserScope()->where('user_id', $user->id)->lockForUpdate()->findOrFail($current->id);
            if ($locked->revision !== (int) $data['revision']) {
                $last = DesignRevision::withoutUserScope()->where('design_id', $locked->id)->where('revision', $locked->revision)->first();
                $by = $last?->author_id !== null ? User::query()->whereKey($last->author_id)->value('name') : null;
                throw new StudioDesignConflict($locked->revision, $by);
            }
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
            $this->snapshot($locked, $actor->id, $coalesce);

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

    /**
     * «Guardar como copia» tras un conflicto: el trabajo local de quien edita se conserva en un diseño nuevo de la misma marca.
     * La copia pertenece al propietario del original; si la guarda un editor, este conserva acceso de editor a ella.
     */
    public function saveCopy(User $actor, Design $design, array $data): Design
    {
        $owner = $this->collab->authorize($actor, $design, 'edit');
        $source = $this->owned($owner, $design);
        $brand = Brand::withoutUserScope()->where('user_id', $owner->id)->findOrFail($source->brand_id);

        return DB::transaction(function () use ($actor, $owner, $brand, $source, $data): Design {
            $copy = $this->create($owner, $brand, ['name' => trim((string) ($data['name'] ?? '')) !== '' ? $data['name'] : mb_substr($source->name, 0, 240).' (copia)',
                'document' => $data['document'] ?? $source->document, 'folder_id' => $source->folder_id, 'is_template' => false], $source->kit_reference ?? []);
            if ($actor->id !== $owner->id) {
                DesignShare::query()->create(['design_id' => $copy->id, 'owner_id' => $owner->id, 'user_id' => $actor->id, 'role' => 'editor']);
            }

            return $copy;
        });
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

    public function present(User $user, Design $design, bool $api = false): array
    {
        $role = $this->collab->role($user, $design);
        $user = $this->collab->authorize($user, $design, 'view');
        $current = $this->owned($user, $design, $design->trashed());

        $data = $current->only(['id', 'user_id', 'brand_id', 'folder_id', 'name', 'document', 'revision', 'is_template', 'kit_reference', 'created_at', 'updated_at', 'deleted_at']);
        if ($data['kit_reference'] !== null) {
            $data['kit_reference'] = self::reference($data['kit_reference']);
        }
        if ($role !== 'owner') {
            $data['folder_id'] = null;
        }

        return $data + ['role' => $role, 'template_fields' => StudioAutomation::fields($current->document), 'fonts' => app(StudioFonts::class)->neededFor($user, Brand::withoutUserScope()->findOrFail($current->brand_id), $current->document, $api)];
    }

    /** Preserve legacy meaning without recapturing a newer brand kit or serializing an empty list. */
    public static function reference(?array $reference): array
    {
        return ['palette' => $reference['palette'] ?? [], 'fonts' => $reference['fonts'] ?? [], 'logos' => $reference['logos'] ?? []] + (isset($reference['font_ids']) ? ['font_ids' => $reference['font_ids']] : []);
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

        return $kit?->only(['palette', 'fonts', 'font_ids', 'logos', 'guidelines', 'voice']) ?? ['palette' => ['#7c3aed', '#111827', '#ffffff'], 'fonts' => ['Arial'], 'logos' => [], 'guidelines' => '', 'voice' => ''];
    }

    public function updateKit(User $user, Brand $brand, array $data): array
    {
        $this->assets->assertBrand($user, $brand);
        $data = Validator::make($data, ['palette' => ['sometimes', 'array', 'max:50'], 'palette.*' => ['required', 'string'],
            'fonts' => ['sometimes', 'array', 'max:50'], 'fonts.*' => ['required', 'string', 'max:100'],
            'font_ids' => ['sometimes', 'array', 'max:50'], 'font_ids.*' => ['nullable', 'integer', 'min:1'],
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
        $current = $this->kit($user, $brand);
        if (! array_key_exists('font_ids', $data) && isset($data['fonts']) && $data['fonts'] !== $current['fonts']) {
            $data['font_ids'] = array_fill(0, count($data['fonts']), null);
        }
        $merged = array_replace($current, $data);
        if (isset($merged['font_ids']) && count($merged['font_ids']) !== count($merged['fonts'])) {
            throw ValidationException::withMessages(['font_ids' => ['Los identificadores deben estar alineados con las familias.']]);
        }
        $ids = array_values(array_unique(array_filter($merged['font_ids'] ?? [], fn ($id) => $id !== null)));
        DB::transaction(function () use ($user, $brand, $merged, $ids): void {
            $fonts = StudioFont::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->where('status', 'ready')->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
            if ($fonts->count() !== count($ids)) {
                throw ValidationException::withMessages(['font_ids' => ['Las tipografías deben pertenecer a esta marca.']]);
            }
            foreach ($merged['font_ids'] ?? [] as $index => $id) {
                if ($id !== null && $fonts[$id]->family !== $merged['fonts'][$index]) {
                    throw ValidationException::withMessages(['font_ids' => ['La familia debe corresponder a la tipografía seleccionada.']]);
                }
            }
            $kit = BrandKit::withoutUserScope()->updateOrCreate(['user_id' => $user->id, 'brand_id' => $brand->id], $merged);
            DB::table('studio_brand_kit_fonts')->where('kit_id', $kit->id)->delete();
            foreach ($ids as $id) {
                DB::table('studio_brand_kit_fonts')->insert(['kit_id' => $kit->id, 'font_id' => $id]);
            }
        });

        return $this->kit($user, $brand);
    }

    private function input(array $data, bool $updating = false): array
    {
        $rules = ['name' => ['required', 'string', 'max:255'], 'document' => ['required', 'array'], 'folder_id' => ['nullable', 'integer', 'min:1'], 'is_template' => ['sometimes', 'boolean']];
        if ($updating) {
            $rules['revision'] = ['required', 'integer', 'min:1'];
            $rules['kit_revision'] = ['sometimes', 'integer', 'min:1'];
            $rules['autosave'] = ['sometimes', 'boolean'];
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

    /** Historial de versiones del diseño (más reciente primero), para cualquiera con acceso de lectura. */
    public function versions(User $user, Design $design): array
    {
        $owner = $this->collab->authorize($user, $design, 'view');
        $current = $this->owned($owner, $design, $design->trashed());
        $rows = DesignRevision::withoutUserScope()->where('design_id', $current->id)->orderByDesc('revision')->limit(100)->get(['id', 'revision', 'name', 'author_id', 'created_at']);
        $names = User::query()->whereIn('id', $rows->pluck('author_id')->filter()->unique())->pluck('name', 'id');

        return $rows->map(fn (DesignRevision $row) => ['revision' => $row->revision, 'name' => $row->name, 'author' => $row->author_id !== null ? ($names[$row->author_id] ?? null) : null,
            'created_at' => $row->created_at?->toIso8601String(), 'current' => $row->revision === $current->revision])->all();
    }

    /** Restaura una versión como una revisión nueva (el historial no se reescribe) con el mismo control de conflicto que un guardado. */
    public function restoreVersion(User $user, Design $design, int $version, mixed $expectedRevision): Design
    {
        $owner = $this->collab->authorize($user, $design, 'edit');
        $snapshot = DesignRevision::withoutUserScope()->where('design_id', $design->id)->where('user_id', $owner->id)->where('revision', $version)->first();
        if ($snapshot === null) {
            throw ValidationException::withMessages(['version' => ['La versión no existe en este diseño.']]);
        }
        $data = ['name' => $snapshot->name, 'document' => $snapshot->document, 'revision' => $expectedRevision];
        if ($snapshot->kit_reference !== null) {
            $data['kit_revision'] = $version;
        }

        return $this->update($user, $design, $data);
    }

    /**
     * Guarda la revisión del diseño. Un autoguardado ($coalesce) del mismo autor dentro del intervalo mínimo
     * (`studio.revisions.min_interval_seconds`) reescribe la revisión reciente en lugar de crear otra; los guardados
     * explícitos, al cerrar la sesión, restaurar o aplicar kit siempre crean revisión (A-07).
     */
    private function snapshot(Design $design, ?int $authorId = null, bool $coalesce = false): void
    {
        $ids = array_values(array_unique(array_merge(StudioFonts::idsFromDocument($design->document), array_filter($design->kit_reference['font_ids'] ?? [], fn ($id) => $id !== null))));
        $fonts = StudioFont::withoutUserScope()->where('user_id', $design->user_id)->where('brand_id', $design->brand_id)->whereIn('id', $ids)->lockForUpdate()->get();
        abort_unless($fonts->count() === count($ids), 422, 'Las tipografías deben pertenecer a esta marca.');
        $author = $authorId ?? $design->user_id;
        $attributes = ['author_id' => $author, 'revision' => $design->revision, 'name' => $design->name, 'document' => $design->document, 'kit_reference' => $design->kit_reference];
        $interval = (int) config('studio.revisions.min_interval_seconds', 300);
        $revision = null;
        if ($coalesce && $interval > 0) {
            $latest = DesignRevision::withoutUserScope()->where('design_id', $design->id)->orderByDesc('revision')->first();
            if ($latest !== null && $latest->revision > 1 && (int) $latest->author_id === (int) $author && $latest->created_at !== null && $latest->created_at->gt(now()->subSeconds($interval))) {
                $latest->update($attributes);
                DB::table('studio_font_usages')->where('revision_id', $latest->id)->delete();
                $revision = $latest;
            }
        }
        $revision ??= DesignRevision::create($attributes + ['user_id' => $design->user_id, 'design_id' => $design->id]);
        foreach ($ids as $id) {
            DB::table('studio_font_usages')->insert(['user_id' => $design->user_id, 'font_id' => $id, 'revision_id' => $revision->id]);
        }
    }
}
