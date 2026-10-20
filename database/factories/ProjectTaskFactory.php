<?php

namespace Database\Factories;

use App\Models\Milestone;
use App\Models\ProjectAssignment;
use App\Models\ProjectTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectTask> */
class ProjectTaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'milestone_id' => null,
            'project_assignment_id' => fn (array $attributes) => $attributes['milestone_id']
                ? Milestone::findOrFail($attributes['milestone_id'])->project_assignment_id : ProjectAssignment::factory()->create()->id,
            'created_by' => fn (array $attributes) => ProjectAssignment::findOrFail($attributes['project_assignment_id'])->project->client_id,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'due_date' => fn (array $attributes) => $attributes['milestone_id']
                ? Milestone::findOrFail($attributes['milestone_id'])->due_date->toDateString()
                : ProjectAssignment::findOrFail($attributes['project_assignment_id'])->agreed_deadline->toDateString(),
            'completed_at' => null,
        ];
    }
}
