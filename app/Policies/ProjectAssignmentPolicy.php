<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;

class ProjectAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isFreelancer();
    }

    public function view(User $user, ProjectAssignment $assignment): bool
    {
        return $user->isFreelancer() && $assignment->freelancer_id === $user->id;
    }

    public function viewPlan(User $user, ProjectAssignment $assignment, Project $project): bool
    {
        return $assignment->project_id === $project->id && (
            ($user->isClient() && $project->client_id === $user->id)
            || ($user->isFreelancer() && $assignment->freelancer_id === $user->id)
        );
    }

    public function managePlan(User $user, ProjectAssignment $assignment, Project $project): bool
    {
        return $this->viewPlan($user, $assignment, $project) && $user->isClient()
            && $project->client_id === $user->id && $project->status === ProjectStatus::InProgress;
    }
}
