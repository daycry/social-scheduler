<?php

namespace App\Services;

use App\Models\SavedText;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Textos guardados (C-01a): fragmentos y grupos de hashtags por usuario. `brand_id` nulo = «Todas las marcas».
 * La propiedad la fija el UserScope del modelo y la comprueba la política; la marca se valida en el request.
 */
class SavedTextService
{
    /**
     * Reglas de validacion del alta y la edicion: las comparten la web y el MCP. La marca, si se informa, debe ser del usuario.
     *
     * @return array<string, mixed>
     */
    public static function rules(?int $userId): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(SavedText::KINDS)],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:5000'],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('user_id', $userId)],
        ];
    }

    /**
     * Textos del usuario con el nombre de la marca, el mas reciente primero (orden total por id). Con `$brandId`, solo los de
     * «Todas las marcas» mas los de esa marca.
     *
     * @return Builder<SavedText>
     */
    public function query(User $user, ?int $brandId = null): Builder
    {
        return SavedText::withoutUserScope()->where('user_id', $user->id)->with('brand:id,name')
            ->when($brandId, fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('brand_id')->orWhere('brand_id', $brandId)))
            ->latest('id');
    }

    /** Todos los textos del usuario, con el nombre de la marca (el más reciente primero). */
    public function list(User $user): Collection
    {
        return $this->query($user)->get();
    }

    /**
     * Los que ofrece el compositor de una marca: los de «Todas las marcas» más los de esa marca.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forBrand(User $user, int $brandId): array
    {
        return $this->query($user, $brandId)->reorder('title')->orderBy('id')->get()->map(fn (SavedText $t) => $this->toArray($t))->values()->all();
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
