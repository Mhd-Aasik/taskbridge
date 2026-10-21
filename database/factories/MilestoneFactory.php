<?php

namespace Database\Factories;

use App\Models\Milestone;
use App\Models\ProjectAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Milestone> */
class MilestoneFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_assignment_id' => ProjectAssignment::factory(),
            'created_by' => fn (array $attributes) => ProjectAssignment::findOrFail($attributes['project_assignment_id'])->project->client_id,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'due_date' => fn (array $attributes) => ProjectAssignment::findOrFail($attributes['project_assignment_id'])->agreed_deadline->toDateString(),
            'position' => fake()->unique()->numberBetween(1, 1000000),
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'completed_at' => CarbonImmutable::now('UTC'),
            'completed_by' => fn (array $resolved) => ProjectAssignment::findOrFail($resolved['project_assignment_id'])->project->client_id,
            'completion_note' => 'The planned outcome has been delivered.',
        ]);
    }
}
