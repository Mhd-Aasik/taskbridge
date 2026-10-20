<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\ProjectTask;
use App\Models\User;

class ProjectTaskPolicy
{
    public function create(User $user, ProjectAssignment $assignment, Project $project): bool
    {
        return app(ProjectAssignmentPolicy::class)->managePlan($user, $assignment, $project);
    }

    public function update(User $user, ProjectTask $task, ProjectAssignment $assignment, Project $project): bool
    {
        return $task->project_assignment_id === $assignment->id && $this->create($user, $assignment, $project);
    }

    public function changeStatus(User $user, ProjectTask $task, ProjectAssignment $assignment, Project $project): bool
    {
        return $task->project_assignment_id === $assignment->id && $project->status === ProjectStatus::InProgress
            && app(ProjectAssignmentPolicy::class)->viewPlan($user, $assignment, $project);
    }
}
