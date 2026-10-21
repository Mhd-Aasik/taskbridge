<?php

namespace App\Actions\Milestones;

use App\Enums\NotificationEvent;
use App\Enums\ProjectStatus;
use App\Models\Milestone;
use App\Services\ProjectNotifications;
use App\Support\CompletionReadiness;
use App\Support\CompletionRules;
use App\Support\ProjectPlanning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CompleteMilestone
{
    public function complete(int $projectId, int $milestoneId, array $input = []): Milestone
    {
        return DB::transaction(function () use ($projectId, $milestoneId, $input): Milestone {
            [$user, $project, $assignment] = ProjectPlanning::context($projectId, lock: true);
            $milestone = $assignment->milestones()->whereKey($milestoneId)->lockForUpdate()->firstOrFail();
            Gate::forUser($user)->authorize('complete', [$milestone, $assignment, $project]);
            if ($milestone->isCompleted()) {
                $note = CompletionRules::note($input);
                CompletionRules::assertRetry($milestone, $user->id, $note);

                return $milestone;
            }
            if ($project->status !== ProjectStatus::InProgress) {
                throw ValidationException::withMessages(['completion' => 'Milestones can be completed only while the project is in progress.']);
            }
            $readiness = CompletionReadiness::forMilestone($milestone);
            $note = CompletionRules::note($input, $readiness['requires_note']);
            if (! $readiness['ready']) {
                throw ValidationException::withMessages(['completion' => implode(' ', $readiness['reasons'])]);
            }
            $milestone->completed_at = CarbonImmutable::now('UTC');
            $milestone->completer()->associate($user);
            $milestone->completion_note = $note;
            if (! $milestone->save()) {
                throw new RuntimeException('The milestone completion could not be saved.');
            }

            app(ProjectNotifications::class)->record(NotificationEvent::MilestoneCompleted, $milestone);

            return $milestone;
        }, 3);
    }
}
