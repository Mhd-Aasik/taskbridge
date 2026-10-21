<?php

namespace Database\Factories;

use App\Enums\DeliverableReviewDecision;
use App\Models\DeliverableReview;
use App\Models\DeliverableVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeliverableReview> */
class DeliverableReviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'deliverable_version_id' => DeliverableVersion::factory(),
            'reviewed_by' => fn (array $attributes) => DeliverableVersion::findOrFail($attributes['deliverable_version_id'])->deliverable->assignment->project->client_id,
            'decision' => DeliverableReviewDecision::Approved,
            'remarks' => null,
            'reviewed_at' => CarbonImmutable::now('UTC'),
        ];
    }

    public function changesRequested(): static
    {
        return $this->state(fn () => [
            'decision' => DeliverableReviewDecision::ChangesRequested,
            'remarks' => 'Please revise the submitted work using the project brief.',
        ]);
    }
}
