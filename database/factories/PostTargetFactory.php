<?php

namespace Database\Factories;

use App\Connectors\Network;
use App\Enums\ContentType;
use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PostTarget> */
class PostTargetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'user_id' => fn (array $attributes) => Post::withoutUserScope()->find($attributes['post_id'])->user_id,
            'social_account_id' => fn (array $attributes) => SocialAccount::factory()->create([
                'brand_id' => Post::withoutUserScope()->find($attributes['post_id'])->brand_id,
                'network' => Network::Instagram,
            ])->id,
            'network' => Network::Instagram,
            'content_type' => ContentType::InstagramImage,
            'status' => PostStatus::Scheduled,
        ];
    }

    public function status(PostStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
