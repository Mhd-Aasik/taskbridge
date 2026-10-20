<?php

namespace App\Actions\Milestones;

use App\Enums\ProjectStatus;
use App\Models\Milestone;
use App\Support\MilestoneRules;
use App\Support\ProjectPlanning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SaveMilestone
{
    public function save(int $projectId, ?int $milestoneId, array $input): Milestone
    {
        return DB::transaction(function () use ($projectId, $milestoneId, $input): Milestone {
            [$user, $project, $assignment] = ProjectPlanning::context($projectId, lock: true);
            // Viewing an assigned plan as a freelancer does not permit any mutation.
            Gate::forUser($user)->authorize('view', $project);
            $milestone = $milestoneId === null ? null : $assignment->milestones()->whereKey($milestoneId)->lockForUpdate()->firstOrFail();
            if ($project->status !== ProjectStatus::InProgress) {
                throw ValidationException::withMessages(['plan' => 'This plan is read-only because the project is no longer in progress. Your changes have not been saved.']);
            }
            Gate::forUser($user)->authorize($milestone ? 'update' : 'create', $milestone
                ? [$milestone, $assignment, $project] : [Milestone::class, $assignment, $project]);
            $details = MilestoneRules::validatedDetails($assignment, $input);
            if ($milestone) {
                $milestone->fill($details);
            } else {
                $position = ((int) $assignment->milestones()->reorder()->orderByDesc('position')->lockForUpdate()->value('position')) + 1;
                if ($position > 4294967295) {
                    throw ValidationException::withMessages(['plan' => 'This plan has reached its milestone ordering limit.']);
                }
                $milestone = new Milestone($details);
                $milestone->assignment()->associate($assignment);
                $milestone->creator()->associate($user);
                $milestone->position = $position;
            }
            if (! $milestone->save()) {
                throw new RuntimeException('The milestone could not be saved.');
            }

            return $milestone;
        }, 3);
    }
}
