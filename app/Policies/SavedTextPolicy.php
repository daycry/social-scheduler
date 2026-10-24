<?php

namespace App\Policies;

use App\Models\SavedText;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class SavedTextPolicy
{
    public function update(User $user, SavedText $text): bool
    {
        return $this->owns($user, $text, 'update');
    }

    public function delete(User $user, SavedText $text): bool
    {
        return $this->owns($user, $text, 'delete');
    }

    private function owns(User $user, SavedText $text, string $ability): bool
    {
        if ($text->user_id === $user->id) {
            return true;
        }

        Log::warning('access.denied', ['user_id' => $user->id, 'resource' => 'saved_text', 'resource_id' => $text->id, 'ability' => $ability]);

        return false;
    }
}
