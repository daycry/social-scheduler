<?php

namespace Database\Factories;

use App\Models\SavedText;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavedText> */
class SavedTextFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'brand_id' => null,
            'kind' => SavedText::KIND_FRAGMENT,
            'title' => fake()->words(3, true),
            'body' => fake()->sentence(),
        ];
    }
}
