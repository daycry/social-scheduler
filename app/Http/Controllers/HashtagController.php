<?php

namespace App\Http\Controllers;

use App\Connectors\Network;
use App\Models\Brand;
use App\Services\HashtagStats;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sugerencias de hashtags del compositor (C-04a, D5): JSON desde el historico propio de la marca, sin IA. Una marca ajena responde
 * 404 (el binding pasa por el UserScope).
 */
class HashtagController extends Controller
{
    public function __construct(private readonly HashtagStats $stats) {}

    public function index(Request $request, Brand $brand): JsonResponse
    {
        $data = $request->validate(['network' => ['nullable', Rule::enum(Network::class)]]);
        $network = isset($data['network']) ? Network::from($data['network']) : null;

        return response()->json($this->stats->suggest($request->user(), $brand, $network));
    }
}
