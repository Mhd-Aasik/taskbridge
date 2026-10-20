<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Proposal> */
class ProposalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory()->state(['status' => ProjectStatus::Open, 'published_at' => CarbonImmutable::now('UTC')]),
            'freelancer_id' => User::factory()->state(['role' => User::ROLE_FREELANCER]),
            'cover_letter' => fake()->paragraphs(2, true),
            'proposed_budget' => (string) fake()->numberBetween(1000, 500000),
            'proposed_deadline' => fake()->dateTimeBetween('+1 week', '+3 months')->format('Y-m-d'),
            'submitted_at' => CarbonImmutable::now('UTC'),
        ];
    }
}
