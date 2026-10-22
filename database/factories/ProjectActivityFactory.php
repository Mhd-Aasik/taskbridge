<?php

namespace Database\Factories;

use App\Enums\ProjectActivityEvent;
use App\Models\Project;
use App\Models\ProjectActivity;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectActivity> */
class ProjectActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'project_assignment_id' => null,
            'actor_id' => fn (array $attributes) => Project::findOrFail($attributes['project_id'])->client_id,
            'event_type' => ProjectActivityEvent::Published,
            'event_key' => fn (array $attributes) => 'project_published:'.$attributes['project_id'].':0',
            'metadata' => fn (array $attributes) => ['record_id' => $attributes['project_id']],
            'occurred_at' => now('UTC'),
        ];
    }
}
