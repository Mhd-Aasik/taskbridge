<?php

namespace Database\Factories;

use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DeliverableVersion> */
class DeliverableVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deliverable_id' => Deliverable::factory(),
            'version_number' => fn (array $attributes) => (DeliverableVersion::where('deliverable_id', $attributes['deliverable_id'])->max('version_number') ?? 0) + 1,
            'uploaded_by' => fn (array $attributes) => Deliverable::findOrFail($attributes['deliverable_id'])->assignment->freelancer_id,
            'storage_path' => fn (array $attributes) => 'deliverables/'.$attributes['deliverable_id'].'/'.Str::uuid().'.txt',
            'original_filename' => 'deliverable.txt',
            'mime_type' => 'text/plain',
            'byte_size' => 16,
            'sha256' => hash('sha256', 'Factory fixture.'),
            'submission_note' => fake()->optional()->paragraph(),
            'submitted_at' => CarbonImmutable::now('UTC'),
            'submission_id' => fn () => (string) Str::uuid(),
        ];
    }
}
