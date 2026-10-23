<?php

namespace App\Policies;

use App\Models\InboxComment;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class InboxCommentPolicy
{
    public function view(User $user, InboxComment $comment): bool
    {
        return $this->owns($user, $comment, 'view');
    }

    public function update(User $user, InboxComment $comment): bool
    {
        return $this->owns($user, $comment, 'update');
    }

    private function owns(User $user, InboxComment $comment, string $ability): bool
    {
        if ($comment->user_id === $user->id) {
            return true;
        }

        Log::warning('access.denied', ['user_id' => $user->id, 'resource' => 'inbox_comment', 'resource_id' => $comment->id, 'ability' => $ability]);

        return false;
    }
}
