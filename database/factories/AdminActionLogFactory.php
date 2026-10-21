<?php

namespace Database\Factories;

use App\Enums\AdminAction;
use App\Models\AdminActionLog;
use App\Models\Category;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<AdminActionLog> */
class AdminActionLogFactory extends Factory
{
    public function definition(): array
    {
        return ['actor_id' => User::factory()->state(['role' => User::ROLE_ADMIN]), 'subject_type' => (new Category)->getMorphClass(), 'subject_id' => Category::factory(),
            'action' => AdminAction::CategoryCreated, 'before_values' => [],
            'after_values' => fn (array $attributes) => Category::findOrFail($attributes['subject_id'])->only(['name', 'slug', 'description', 'is_active']),
            'occurred_at' => CarbonImmutable::now('UTC'), 'action_id' => (string) Str::uuid(), 'request_hash' => hash('sha256', (string) Str::uuid())];
    }
}
