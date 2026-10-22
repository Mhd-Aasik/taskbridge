<?php

namespace App\Actions\Projects;

use App\Enums\NotificationEvent;
use App\Enums\ProjectActivityEvent;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Services\ProjectActivities;
use App\Services\ProjectNotifications;
use App\Support\CompletionReadiness;
use App\Support\CompletionRules;
use App\Support\ProjectPlanning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CompleteProject
{
    public function complete(int $projectId, array $input = []): Project
    {
        return DB::transaction(function () use ($projectId, $input): Project {
            [$user, $project, $assignment] = ProjectPlanning::context($projectId, lock: true);
            Gate::forUser($user)->authorize('complete', [$project, $assignment]);
            $note = CompletionRules::note($input);
            if ($project->isCompleted()) {
                CompletionRules::assertRetry($project, $user->id, $note);

                return $project;
            }
            if ($project->status !== ProjectStatus::InProgress) {
                throw ValidationException::withMessages(['completion' => 'Only an assigned project that is in progress can be completed. Existing history has not changed.']);
            }
            // Every planning/task/upload/review mutation uses this same assignment lock.
            $readiness = CompletionReadiness::forProject($assignment);
            if (! $readiness['ready']) {
                throw ValidationException::withMessages(['completion' => implode(' ', $readiness['reasons'])]);
            }
            $project->status = ProjectStatus::Completed;
            $project->completed_at = CarbonImmutable::now('UTC');
            $project->completer()->associate($user);
            $project->completion_note = $note;
            if (! $project->save()) {
                throw new RuntimeException('The project completion could not be saved.');
            }

            app(ProjectNotifications::class)->record(NotificationEvent::ProjectCompleted, $project);
            app(ProjectActivities::class)->record(ProjectActivityEvent::Completed, $project, $user);

            return $project;
        }, 3);
    }
}
