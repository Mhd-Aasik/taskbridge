<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Models\Deliverable;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;

class DeliverablePolicy
{
    public function viewAny(User $user, ProjectAssignment $assignment, Project $project): bool
    {
        return app(ProjectAssignmentPolicy::class)->viewPlan($user, $assignment, $project);
    }

    public function view(User $user, Deliverable $deliverable, ProjectAssignment $assignment, Project $project): bool
    {
        return $deliverable->project_assignment_id === $assignment->id && $this->viewAny($user, $assignment, $project);
    }

    public function create(User $user, ProjectAssignment $assignment, Project $project): bool
    {
        return $this->viewAny($user, $assignment, $project) && $user->isFreelancer()
            && $assignment->freelancer_id === $user->id && $project->status === ProjectStatus::InProgress;
    }

    public function uploadVersion(User $user, Deliverable $deliverable, ProjectAssignment $assignment, Project $project): bool
    {
        return $deliverable->project_assignment_id === $assignment->id
            && $this->create($user, $assignment, $project) && ! $deliverable->isApproved()
            && ! $deliverable->milestone?->isCompleted();
    }
}
