<?php

namespace Database\Factories;

use App\Models\ActiveDispute;
use App\Models\DisputeCase;
use App\Models\ProjectAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DisputeCase> */
class DisputeCaseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_assignment_id' => ProjectAssignment::factory(),
            'opened_by' => fn (array $attributes) => ProjectAssignment::findOrFail($attributes['project_assignment_id'])->project->client_id,
            'title' => 'Review the agreed project outcome',
            'reason' => 'Please review the current work and clarify the outcome of this assignment.',
            'opened_at' => fn () => CarbonImmutable::now('UTC'),
            'submission_id' => (string) Str::uuid(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (DisputeCase $dispute): void {
            $active = new ActiveDispute;
            $active->project_assignment_id = $dispute->project_assignment_id;
            $active->dispute_case_id = $dispute->id;
            $active->save();
        });
    }
}
