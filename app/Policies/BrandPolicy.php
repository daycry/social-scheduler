<?php

namespace App\Policies;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class BrandPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Brand $brand): bool
    {
        return $this->owns($user, $brand, 'view');
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Brand $brand): bool
    {
        return $this->owns($user, $brand, 'update');
    }

    public function delete(User $user, Brand $brand): bool
    {
        return $this->owns($user, $brand, 'delete');
    }

    private function owns(User $user, Brand $brand, string $ability): bool
    {
        if ($brand->user_id === $user->id) {
            return true;
        }

        Log::warning('access.denied', [
            'user_id' => $user->id,
            'resource' => 'brand',
            'resource_id' => $brand->id,
            'ability' => $ability,
        ]);

        return false;
    }
}
