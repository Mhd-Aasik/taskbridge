<?php

namespace Database\Factories;

use App\Enums\DisputeStatementKind;
use App\Models\DisputeCase;
use App\Models\DisputeStatement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DisputeStatement> */
class DisputeStatementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'dispute_case_id' => DisputeCase::factory(),
            'submitted_by' => fn (array $attributes) => DisputeCase::findOrFail($attributes['dispute_case_id'])->opened_by,
            'kind' => DisputeStatementKind::Statement,
            'body' => 'Here is the relevant project context for review.',
            'submitted_at' => fn () => CarbonImmutable::now('UTC'),
            'submission_id' => (string) Str::uuid(),
        ];
    }
}
