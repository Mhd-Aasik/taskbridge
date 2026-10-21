<?php

namespace Database\Factories;

use App\Enums\DisputeDecision;
use App\Models\DisputeCase;
use App\Models\DisputeResolution;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DisputeResolution> */
class DisputeResolutionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dispute_case_id' => DisputeCase::factory(),
            'decided_by' => User::factory()->state(['role' => User::ROLE_ADMIN]),
            'decision' => DisputeDecision::Resolved,
            'rationale' => 'The available case statements have been reviewed and this case is closed.',
            'decided_at' => fn () => CarbonImmutable::now('UTC'),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn (DisputeResolution $resolution) => $resolution->dispute->activeRecord?->delete());
    }
}
