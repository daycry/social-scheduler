<?php

namespace App\Policies;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SocialAccountPolicy
{
    public function view(User $user, SocialAccount $account): bool
    {
        return $this->owns($user, $account, 'view');
    }

    public function disconnect(User $user, SocialAccount $account): bool
    {
        return $this->owns($user, $account, 'disconnect');
    }

    private function owns(User $user, SocialAccount $account, string $ability): bool
    {
        if ($account->user_id === $user->id) {
            return true;
        }

        Log::warning('access.denied', [
            'user_id' => $user->id,
            'resource' => 'social_account',
            'resource_id' => $account->id,
            'ability' => $ability,
        ]);

        return false;
    }
}
