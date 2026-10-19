<?php

namespace Database\Factories;

use App\Enums\PostStatus;
use App\Models\Brand;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Post> */
class PostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'user_id' => fn (array $attributes) => Brand::withoutUserScope()->find($attributes['brand_id'])->user_id,
            'text' => fake()->sentence(),
            'scheduled_at' => now()->addDay(),
            'status' => PostStatus::Draft,
        ];
    }
}
