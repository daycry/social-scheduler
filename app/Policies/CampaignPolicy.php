<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class CampaignPolicy
{
    public function view(User $user, Campaign $campaign): bool
    {
        return $this->owns($user, $campaign, 'view');
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->owns($user, $campaign, 'update');
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->owns($user, $campaign, 'delete');
    }

    private function owns(User $user, Campaign $campaign, string $ability): bool
    {
        if ($campaign->user_id === $user->id) {
            return true;
        }

        Log::warning('access.denied', ['user_id' => $user->id, 'resource' => 'campaign', 'resource_id' => $campaign->id, 'ability' => $ability]);

        return false;
    }
}
