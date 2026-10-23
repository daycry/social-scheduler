<?php

namespace App\Policies;

use App\Models\Autolist;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class AutolistPolicy
{
    public function view(User $user, Autolist $autolist): bool
    {
        return $this->owns($user, $autolist, 'view');
    }

    public function update(User $user, Autolist $autolist): bool
    {
        return $this->owns($user, $autolist, 'update');
    }

    public function delete(User $user, Autolist $autolist): bool
    {
        return $this->owns($user, $autolist, 'delete');
    }

    private function owns(User $user, Autolist $autolist, string $ability): bool
    {
        if ($autolist->user_id === $user->id) {
            return true;
        }

        Log::warning('access.denied', ['user_id' => $user->id, 'resource' => 'autolist', 'resource_id' => $autolist->id, 'ability' => $ability]);

        return false;
    }
}
