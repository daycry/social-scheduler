<?php

namespace App\Policies;

use App\Models\PostTemplate;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PostTemplatePolicy
{
    public function view(User $user, PostTemplate $template): bool
    {
        return $this->owns($user, $template, 'view');
    }

    public function update(User $user, PostTemplate $template): bool
    {
        return $this->owns($user, $template, 'update');
    }

    public function delete(User $user, PostTemplate $template): bool
    {
        return $this->owns($user, $template, 'delete');
    }

    private function owns(User $user, PostTemplate $template, string $ability): bool
    {
        if ($template->user_id === $user->id) {
            return true;
        }

        Log::warning('access.denied', ['user_id' => $user->id, 'resource' => 'post_template', 'resource_id' => $template->id, 'ability' => $ability]);

        return false;
    }
}
