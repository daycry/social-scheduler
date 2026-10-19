<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PostPolicy
{
    public function view(User $user, Post $post): bool
    {
        return $this->owns($user, $post, 'view');
    }

    public function update(User $user, Post $post): bool
    {
        return $this->owns($user, $post, 'update');
    }

    public function delete(User $user, Post $post): bool
    {
        return $this->owns($user, $post, 'delete');
    }

    private function owns(User $user, Post $post, string $ability): bool
    {
        if ($post->user_id === $user->id) {
            return true;
        }

        Log::warning('access.denied', ['user_id' => $user->id, 'resource' => 'post', 'resource_id' => $post->id, 'ability' => $ability]);

        return false;
    }
}
