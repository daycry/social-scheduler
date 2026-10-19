<?php

namespace App\Policies;

use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class MediaAssetPolicy
{
    public function view(User $user, MediaAsset $asset): bool
    {
        return $this->owns($user, $asset, 'view');
    }

    public function update(User $user, MediaAsset $asset): bool
    {
        return $this->owns($user, $asset, 'update');
    }

    public function delete(User $user, MediaAsset $asset): bool
    {
        return $this->owns($user, $asset, 'delete');
    }

    private function owns(User $user, MediaAsset $asset, string $ability): bool
    {
        if ($asset->user_id === $user->id) {
            return true;
        }

        Log::warning('access.denied', ['user_id' => $user->id, 'resource' => 'media_asset', 'resource_id' => $asset->id, 'ability' => $ability]);

        return false;
    }
}
