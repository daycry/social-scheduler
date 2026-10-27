<?php

namespace Database\Factories;

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\InboxComment;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InboxComment> */
class InboxCommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => fn () => User::factory()->create()->id,
            'brand_id' => fn (array $a) => Brand::factory()->create(['user_id' => $a['user_id']])->id,
            'social_account_id' => fn (array $a) => SocialAccount::factory()->create(['brand_id' => $a['brand_id'], 'user_id' => $a['user_id']])->id,
            'network' => Network::Instagram,
            'external_id' => 'c-'.fake()->unique()->numerify('########'),
            'author_name' => fake()->userName(),
            'text' => fake()->sentence(),
            'is_own' => false,
            'hidden' => false,
            'commented_at' => now()->subHour(),
        ];
    }
}
