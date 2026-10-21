<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\ProjectAssignment;
use App\Models\ProjectRating;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProjectRating> */
class ProjectRatingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_assignment_id' => ProjectAssignment::factory(),
            'reviewer_id' => fn (array $attributes) => ProjectAssignment::findOrFail($attributes['project_assignment_id'])->project->client_id,
            'reviewee_id' => fn (array $attributes) => ProjectAssignment::findOrFail($attributes['project_assignment_id'])->freelancer_id,
            'rating' => fake()->numberBetween(1, 5), 'feedback' => null,
            'submitted_at' => CarbonImmutable::now('UTC'), 'submission_id' => (string) Str::uuid(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ProjectRating $rating): void {
            $project = $rating->assignment->project;
            if ($project->completed_at === null) {
                $project->forceFill(['status' => ProjectStatus::Completed, 'completed_at' => $rating->submitted_at, 'completed_by' => $project->client_id])->save();
            }
        });
    }
}
