<?php

namespace App\Http\Controllers;

use App\Connectors\Meta\Instagram\InstagramAudio;
use App\Models\Brand;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class InstagramAudioController extends Controller
{
    public function __invoke(Request $request, Brand $brand, int $account, ?string $audio = null): JsonResponse
    {
        abort_unless($brand->user_id === $request->user()->id, 404);
        $account = SocialAccount::withoutUserScope()->where('user_id', $request->user()->id)->where('brand_id', $brand->id)->findOrFail($account);
        $data = $request->validate(['q' => ['nullable', 'string', 'max:200'], 'type' => ['nullable', 'in:all,music,original_sound'], 'cursor' => ['nullable', 'string', 'max:8192']]);
        try {
            $catalog = app(InstagramAudio::class);

            return response()->json($audio === null
                ? $catalog->search($account, $data['q'] ?? '', $data['type'] ?? 'all', $data['cursor'] ?? null)
                : ['track' => $catalog->get($account, $audio)]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
