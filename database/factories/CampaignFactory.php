<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Campaign> */
class CampaignFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'user_id' => fn (array $attributes) => Brand::withoutUserScope()->find($attributes['brand_id'])->user_id,
            'name' => fake()->words(2, true),
            'color' => '#4f46e5',
            'starts_on' => null,
            'ends_on' => null,
            'archived_at' => null,
        ];
    }
}
