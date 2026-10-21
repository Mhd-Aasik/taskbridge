<?php

namespace Database\Factories;

use App\Models\ProjectAssignment;
use App\Models\ProjectMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ProjectMessage> */
class ProjectMessageFactory extends Factory
{
    public function definition(): array
    {
        return ['project_assignment_id' => ProjectAssignment::factory(),
            'sender_id' => fn (array $attributes) => ProjectAssignment::findOrFail($attributes['project_assignment_id'])->project->client_id,
            'body' => 'Here is the next update on our agreed project.', 'sent_at' => fn () => CarbonImmutable::now('UTC'),
            'submission_id' => fn () => (string) Str::uuid()];
    }
}
