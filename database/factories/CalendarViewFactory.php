<?php

namespace Database\Factories;

use App\Models\CalendarView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CalendarView> */
class CalendarViewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->unique()->words(3, true),
            'filters' => ['view' => 'week'],
            'is_default' => false,
        ];
    }
}
