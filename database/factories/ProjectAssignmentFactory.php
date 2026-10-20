<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\ProjectAssignment;
use App\Models\Proposal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProjectAssignment> */
class ProjectAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'proposal_id' => Proposal::factory()->state(['status' => ProposalStatus::Accepted]),
            'project_id' => fn (array $attributes) => Proposal::findOrFail($attributes['proposal_id'])->project_id,
            'freelancer_id' => fn (array $attributes) => Proposal::findOrFail($attributes['proposal_id'])->freelancer_id,
            'agreed_budget' => fn (array $attributes) => Proposal::findOrFail($attributes['proposal_id'])->proposed_budget,
            'agreed_deadline' => fn (array $attributes) => Proposal::findOrFail($attributes['proposal_id'])->proposed_deadline->toDateString(),
            'assigned_at' => CarbonImmutable::now('UTC'),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ProjectAssignment $assignment): void {
            $assignment->project->forceFill(['status' => ProjectStatus::InProgress])->save();
            $assignment->proposal->forceFill(['status' => ProposalStatus::Accepted])->save();
        });
    }
}
