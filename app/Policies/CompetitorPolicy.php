<?php

namespace App\Policies;

use App\Models\Competitor;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class CompetitorPolicy
{
    public function view(User $user, Competitor $competitor): bool
    {
        return $this->owns($user, $competitor, 'view');
    }

    public function delete(User $user, Competitor $competitor): bool
    {
        return $this->owns($user, $competitor, 'delete');
    }

    private function owns(User $user, Competitor $competitor, string $ability): bool
    {
        if ($competitor->user_id === $user->id) {
            return true;
        }

        Log::warning('access.denied', ['user_id' => $user->id, 'resource' => 'competitor', 'resource_id' => $competitor->id, 'ability' => $ability]);

        return false;
    }
}
