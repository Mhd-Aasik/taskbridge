<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id' => User::factory()->state(['role' => User::ROLE_CLIENT]),
            'category_id' => Category::factory(),
            'title' => fake()->sentence(6),
            'description' => fake()->paragraphs(3, true),
            'budget' => (string) fake()->numberBetween(1000, 500000),
            'deadline' => fake()->dateTimeBetween('+1 week', '+6 months')->format('Y-m-d'),
        ];
    }
}
