<?php

namespace App\Policies;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;

class MilestonePolicy
{
    public function create(User $user, ProjectAssignment $assignment, Project $project): bool
    {
        return app(ProjectAssignmentPolicy::class)->managePlan($user, $assignment, $project);
    }

    public function update(User $user, Milestone $milestone, ProjectAssignment $assignment, Project $project): bool
    {
        return ! $milestone->isCompleted() && $milestone->project_assignment_id === $assignment->id && $this->create($user, $assignment, $project);
    }

    public function complete(User $user, Milestone $milestone, ProjectAssignment $assignment, Project $project): bool
    {
        return $milestone->project_assignment_id === $assignment->id
            && app(ProjectAssignmentPolicy::class)->viewPlan($user, $assignment, $project)
            && $user->isClient() && $project->client_id === $user->id;
    }
}
