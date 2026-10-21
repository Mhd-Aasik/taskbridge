<?php

namespace App\Actions\Tasks;

use App\Enums\ProjectStatus;
use App\Enums\ProjectTaskStatus;
use App\Models\ProjectTask;
use App\Support\CompletionRules;
use App\Support\ProjectPlanning;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ChangeProjectTaskStatus
{
    public function change(int $projectId, int $taskId, string $status, int $expectedStatusVersion): ProjectTask
    {
        return DB::transaction(function () use ($projectId, $taskId, $status, $expectedStatusVersion): ProjectTask {
            [$user, $project, $assignment] = ProjectPlanning::context($projectId, lock: true);
            $candidate = $assignment->tasks()->findOrFail($taskId);
            $milestone = $candidate->milestone_id === null ? null : $assignment->milestones()->whereKey($candidate->milestone_id)->lockForUpdate()->firstOrFail();
            $task = $assignment->tasks()->whereKey($taskId)->lockForUpdate()->firstOrFail();
            if ($project->status !== ProjectStatus::InProgress) {
                throw ValidationException::withMessages(['plan' => 'This plan is read-only because the project is no longer in progress. Task status has not changed.']);
            }
            CompletionRules::ensureMutable($milestone);
            Gate::forUser($user)->authorize('changeStatus', [$task, $assignment, $project]);
            Validator::make(['task_status' => $status], ['task_status' => ['required', Rule::enum(ProjectTaskStatus::class)]])->validate();
            $target = ProjectTaskStatus::from($status);
            // Repeated confirmations are harmless, even if their earlier token is now stale.
            if ($task->status === $target) {
                return $task;
            }
            if ($task->status_version !== $expectedStatusVersion) {
                throw ValidationException::withMessages(['task_conflict' => 'This task status changed after the page loaded. Review the current status and try again.']);
            }
            $task->status = $target;
            $task->completed_at = $target === ProjectTaskStatus::Done ? CarbonImmutable::now('UTC') : null;
            $task->status_version++;
            if (! $task->save()) {
                throw new RuntimeException('The task status could not be saved.');
            }

            return $task;
        }, 3);
    }
}
