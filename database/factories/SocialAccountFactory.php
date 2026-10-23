<?php

namespace Database\Factories;

use App\Connectors\Network;
use App\Models\Brand;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SocialAccount> */
class SocialAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'user_id' => fn (array $attributes) => Brand::withoutUserScope()->find($attributes['brand_id'])->user_id,
            'network' => Network::Instagram,
            'external_id' => (string) fake()->unique()->numerify('##########'),
            'name' => fake()->company(),
            'username' => fake()->userName(),
            'status' => SocialAccount::STATUS_ACTIVE,
            'access_token' => 'access-'.fake()->sha1(),
            'refresh_token' => 'refresh-'.fake()->sha1(),
            'token_expires_at' => now()->addDays(30),
            'scopes' => [],
            'meta' => [],
            'connected_at' => now(),
        ];
    }

    public function network(Network $network): static
    {
        return $this->state(['network' => $network]);
    }

    public function requiresReconnection(): static
    {
        return $this->state(['status' => SocialAccount::STATUS_RECONNECT, 'last_error' => 'Token revocado']);
    }
}
