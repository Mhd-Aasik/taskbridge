<?php

namespace Database\Factories;

use App\Models\Deliverable;
use App\Models\Milestone;
use App\Models\ProjectAssignment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Deliverable> */
class DeliverableFactory extends Factory
{
    public function definition(): array
    {
        return [
            'milestone_id' => null,
            'project_assignment_id' => fn (array $attributes) => $attributes['milestone_id']
                ? Milestone::findOrFail($attributes['milestone_id'])->project_assignment_id : ProjectAssignment::factory()->create()->id,
            'created_by' => fn (array $attributes) => ProjectAssignment::findOrFail($attributes['project_assignment_id'])->freelancer_id,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
        ];
    }
}
