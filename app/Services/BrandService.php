<?php

namespace App\Services;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Capa unica para web y MCP: exige siempre el usuario explicito, de modo que
 * ninguna ruta de entrada pueda consultar marcas sin identidad.
 */
class BrandService
{
    /** @return Collection<int, Brand> */
    public function list(User $user): Collection
    {
        return Brand::withoutUserScope()->where('user_id', $user->id)->orderBy('name')->get();
    }

    public function find(User $user, int|string $id): ?Brand
    {
        return Brand::withoutUserScope()->where('user_id', $user->id)->find($id);
    }

    /** @param array{name: string, description?: ?string, timezone?: string} $data */
    public function create(User $user, array $data): Brand
    {
        $brand = new Brand($data);
        $brand->user_id = $user->id;
        $brand->save();

        return $brand;
    }

    /** @param array{name?: string, description?: ?string, timezone?: string} $data */
    public function update(Brand $brand, array $data): Brand
    {
        $brand->update($data);

        return $brand;
    }

    public function delete(Brand $brand): void
    {
        $brand->delete();
    }
}
