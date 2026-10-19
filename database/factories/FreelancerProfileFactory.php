<?php

namespace Database\Factories;

use App\Models\FreelancerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FreelancerProfile> */
class FreelancerProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => User::ROLE_FREELANCER]),
            'headline' => fake()->jobTitle(),
            'bio' => fake()->paragraph(),
            'location' => fake()->city(),
            'portfolio_url' => fake()->url(),
            'hourly_rate' => (string) fake()->numberBetween(500, 10000),
            'is_public' => false,
        ];
    }
}
