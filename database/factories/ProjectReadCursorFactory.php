<?php

namespace Database\Factories;

use App\Models\ProjectMessage;
use App\Models\ProjectReadCursor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectReadCursor> */
class ProjectReadCursorFactory extends Factory
{
    public function definition(): array
    {
        return ['last_read_message_id' => ProjectMessage::factory(),
            'project_assignment_id' => fn (array $attributes) => ProjectMessage::findOrFail($attributes['last_read_message_id'])->project_assignment_id,
            'user_id' => fn (array $attributes) => ProjectMessage::findOrFail($attributes['last_read_message_id'])->assignment->freelancer_id];
    }
}
