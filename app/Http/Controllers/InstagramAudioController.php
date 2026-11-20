<?php

namespace App\Http\Controllers;

use App\Connectors\Meta\Instagram\InstagramAudio;
use App\Models\Brand;
use App\Models\SocialAccount;
use App\Support\Ownership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class InstagramAudioController extends Controller
{
    public function __construct(private readonly InstagramAudio $catalog) {}

    public function __invoke(Request $request, Brand $brand, int $account, ?string $audio = null): JsonResponse
    {
        Ownership::assert($brand, $request->user());
        $account = SocialAccount::ownedBy($request->user()->id)->where('brand_id', $brand->id)->findOrFail($account);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'type' => ['nullable', 'in:all,music,original_sound'], 'cursor' => ['nullable', 'string', 'max:8192']]);
        try {
            return response()->json($audio === null
                ? $this->catalog->search($account, $data['q'] ?? '', $data['type'] ?? 'all', $data['cursor'] ?? null)
                : ['track' => $this->catalog->get($account, $audio)]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
