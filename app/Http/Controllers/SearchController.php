<?php

namespace App\Http\Controllers;

use App\Services\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Búsqueda global de la paleta de comandos (T-85). Solo JSON: las páginas y acciones de la app se filtran en el cliente desde `navigation.js`. */
class SearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'min:'.GlobalSearch::MIN_LENGTH, 'max:'.GlobalSearch::MAX_LENGTH],
        ], [
            'q.required' => 'Escribe qué quieres buscar.',
            'q.min' => 'Escribe al menos '.GlobalSearch::MIN_LENGTH.' caracteres.',
            'q.max' => 'La búsqueda admite '.GlobalSearch::MAX_LENGTH.' caracteres como máximo.',
        ]);

        return response()->json(['results' => $search->search($request->user(), $data['q'])]);
    }
}
