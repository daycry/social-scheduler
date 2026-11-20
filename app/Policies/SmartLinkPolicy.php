<?php

namespace App\Policies;

use App\Models\SmartLink;
use App\Models\User;
use App\Support\Ownership;
use Illuminate\Support\Facades\Log;

class SmartLinkPolicy
{
    public function view(User $user, SmartLink $link): bool
    {
        return $this->owns($user, $link, 'view');
    }

    public function update(User $user, SmartLink $link): bool
    {
        return $this->owns($user, $link, 'update');
    }

    public function delete(User $user, SmartLink $link): bool
    {
        return $this->owns($user, $link, 'delete');
    }

    private function owns(User $user, SmartLink $link, string $ability): bool
    {
        if (Ownership::matches($link, $user)) {
            return true;
        }

        Log::warning('access.denied', ['user_id' => $user->id, 'resource' => 'smart_link', 'resource_id' => $link->id, 'ability' => $ability]);

        return false;
    }
}
