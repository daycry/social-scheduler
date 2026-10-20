<?php

namespace App\Support;

use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Marca seleccionada en el shell. Prioridad: `?brand_id=` de la query (si es del usuario; se persiste en la
 * sesion) > marca guardada en la sesion > primera marca por nombre. Una marca ajena en la query se ignora aqui
 * (los controladores que la exigen responden 404 por su cuenta).
 */
class CurrentBrand
{
    public const SESSION_KEY = 'current_brand_id';

    /** Marcas del usuario (el UserScope ya limita) — una sola consulta por peticion. */
    public static function brands(Request $request): Collection
    {
        if (! $request->attributes->has('shell.brands')) {
            $request->attributes->set('shell.brands', Brand::query()->orderBy('name')->get());
        }

        return $request->attributes->get('shell.brands');
    }

    /** Marca elegida explicitamente (query o sesion), sin caer en la primera. */
    public static function explicit(Request $request): ?Brand
    {
        $brands = self::brands($request);
        $query = $request->query('brand_id');
        $routeBrand = $request->route('brand');
        if ($routeBrand instanceof Brand && $brands->contains('id', $routeBrand->id)) {
            if ($request->hasSession()) {
                $request->session()->put(self::SESSION_KEY, $routeBrand->id);
            }

            return $routeBrand;
        }

        if (is_numeric($query) && ($found = $brands->firstWhere('id', (int) $query))) {
            if ($request->hasSession()) {
                $request->session()->put(self::SESSION_KEY, $found->id);
            }

            return $found;
        }

        if ($request->hasSession() && ($id = $request->session()->get(self::SESSION_KEY))) {
            return $brands->firstWhere('id', (int) $id);
        }

        return null;
    }

    public static function resolve(Request $request): ?Brand
    {
        return self::explicit($request) ?? self::brands($request)->first();
    }
}
