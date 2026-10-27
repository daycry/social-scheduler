<?php

namespace Database\Factories;

use App\Models\InboxComment;
use App\Models\InboxCommentNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InboxCommentNote> */
class InboxCommentNoteFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'inbox_comment_id' => fn (array $attributes) => InboxComment::factory()->create(['user_id' => $attributes['user_id']])->id,
            'body' => fake()->sentence(),
        ];
    }
}
