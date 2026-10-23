<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Report> */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'brand_id' => Brand::factory(),
            'format' => 'csv',
            'from' => '2026-10-01',
            'to' => '2026-10-30',
            'compare' => 'none',
            'status' => Report::STATUS_PENDING,
        ];
    }
}
