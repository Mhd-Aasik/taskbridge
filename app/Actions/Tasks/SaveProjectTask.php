<?php

namespace App\Actions\Tasks;

use App\Enums\ProjectStatus;
use App\Models\ProjectTask;
use App\Support\ProjectPlanning;
use App\Support\ProjectTaskRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SaveProjectTask
{
    public function save(int $projectId, ?int $taskId, array $input, ?int $expectedDetailsVersion = null): ProjectTask
    {
        return DB::transaction(function () use ($projectId, $taskId, $input, $expectedDetailsVersion): ProjectTask {
            [$user, $project, $assignment] = ProjectPlanning::context($projectId, lock: true);
            Gate::forUser($user)->authorize('view', $project);
            $milestoneId = ProjectTaskRules::milestoneId($input);
            $milestone = $milestoneId === null ? null : $assignment->milestones()->whereKey($milestoneId)->lockForUpdate()->first();
            if ($milestoneId !== null && ! $milestone) {
                throw ValidationException::withMessages(['milestone_id' => 'Choose a milestone from this project plan, or leave the task ungrouped.']);
            }
            $task = $taskId === null ? null : $assignment->tasks()->whereKey($taskId)->lockForUpdate()->firstOrFail();
            if ($project->status !== ProjectStatus::InProgress) {
                throw ValidationException::withMessages(['plan' => 'This plan is read-only because the project is no longer in progress. Your task changes have not been saved.']);
            }
            Gate::forUser($user)->authorize($task ? 'update' : 'create', $task
                ? [$task, $assignment, $project] : [ProjectTask::class, $assignment, $project]);
            if ($task && $task->details_version !== $expectedDetailsVersion) {
                throw ValidationException::withMessages(['task_conflict' => 'This task was edited after you opened it. Reload the task before saving; your entered values have been kept.']);
            }
            $details = ProjectTaskRules::validatedDetails($assignment, $milestone, $input);
            if ($task) {
                $task->fill($details);
                $task->milestone()->associate($milestone);
                if (! $task->isDirty(['title', 'description', 'due_date', 'milestone_id'])) {
                    return $task;
                }
                $task->details_version++;
            } else {
                $task = new ProjectTask($details);
                $task->assignment()->associate($assignment);
                $task->milestone()->associate($milestone);
                $task->creator()->associate($user);
            }
            if (! $task->save()) {
                throw new RuntimeException('The task could not be saved.');
            }

            return $task;
        }, 3);
    }
}
