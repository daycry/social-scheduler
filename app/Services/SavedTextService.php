<?php

namespace App\Services;

use App\Models\SavedText;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Textos guardados (C-01a): fragmentos y grupos de hashtags por usuario. `brand_id` nulo = «Todas las marcas».
 * La propiedad la fija el UserScope del modelo y la comprueba la política; la marca se valida en el request.
 */
class SavedTextService
{
    /** Todos los textos del usuario, con el nombre de la marca (el más reciente primero). */
    public function list(User $user): Collection
    {
        return SavedText::withoutUserScope()->where('user_id', $user->id)->with('brand:id,name')->latest('id')->get();
    }

    /**
     * Los que ofrece el compositor de una marca: los de «Todas las marcas» más los de esa marca.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forBrand(User $user, int $brandId): array
    {
        return SavedText::withoutUserScope()->where('user_id', $user->id)
            ->where(fn ($q) => $q->whereNull('brand_id')->orWhere('brand_id', $brandId))
            ->with('brand:id,name')
            ->orderBy('title')->get()->map(fn (SavedText $t) => $this->toArray($t))->values()->all();
    }

    /** @param array{kind: string, title: string, body: string, brand_id?: int|null} $data */
    public function create(User $user, array $data): SavedText
    {
        $text = new SavedText($data);
        $text->brand_id = $data['brand_id'] ?? null;
        $text->user_id = $user->id;
        $text->save();

        return $text;
    }

    /** @param array{kind: string, title: string, body: string, brand_id?: int|null} $data */
    public function update(SavedText $text, array $data): SavedText
    {
        $text->fill($data);
        $text->brand_id = $data['brand_id'] ?? null;
        $text->save();

        return $text;
    }

    public function delete(SavedText $text): void
    {
        $text->delete();
    }

    /** @return array<string, mixed> */
    public function toArray(SavedText $t): array
    {
        return ['id' => $t->id, 'kind' => $t->kind, 'title' => $t->title, 'body' => $t->body, 'brand_id' => $t->brand_id, 'brand' => $t->brand?->name];
    }
}
