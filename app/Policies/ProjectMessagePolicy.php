<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Models\DisputeCase;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;

class ProjectMessagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isClient() || $user->isFreelancer();
    }

    public function viewConversation(User $user, ProjectAssignment $assignment, Project $project): bool
    {
        return $assignment->project_id === $project->id
            && in_array($project->status, [ProjectStatus::InProgress, ProjectStatus::Completed, ProjectStatus::Cancelled], true)
            && (($user->isClient() && $project->client_id === $user->id) || ($user->isFreelancer() && $assignment->freelancer_id === $user->id));
    }

    public function send(User $user, ProjectAssignment $assignment, Project $project): bool
    {
        return $this->viewConversation($user, $assignment, $project);
    }

    public function markRead(User $user, ProjectAssignment $assignment, Project $project): bool
    {
        return $this->viewConversation($user, $assignment, $project);
    }

    public function viewForDispute(User $user, DisputeCase $dispute, ProjectAssignment $assignment, Project $project): bool
    {
        return $user->isAdmin() && $dispute->project_assignment_id === $assignment->id && $assignment->project_id === $project->id;
    }
}
