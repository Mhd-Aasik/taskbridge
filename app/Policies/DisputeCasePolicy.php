<?php

namespace App\Policies;

use App\Models\DeliverableVersion;
use App\Models\DisputeCase;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;

class DisputeCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isClient() || $user->isFreelancer();
    }

    public function viewAdminAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user, ProjectAssignment $assignment, Project $project): bool
    {
        return $assignment->project_id === $project->id && (($user->isClient() && $project->client_id === $user->id)
            || ($user->isFreelancer() && $assignment->freelancer_id === $user->id));
    }

    public function viewParticipant(User $user, DisputeCase $dispute, ProjectAssignment $assignment, Project $project): bool
    {
        return $dispute->project_assignment_id === $assignment->id && $this->create($user, $assignment, $project);
    }

    public function viewAdmin(User $user, DisputeCase $dispute, ProjectAssignment $assignment, Project $project): bool
    {
        return $user->isAdmin() && $dispute->project_assignment_id === $assignment->id && $assignment->project_id === $project->id;
    }

    public function view(User $user, DisputeCase $dispute, ProjectAssignment $assignment, Project $project): bool
    {
        return $this->viewParticipant($user, $dispute, $assignment, $project) || $this->viewAdmin($user, $dispute, $assignment, $project);
    }

    public function submitStatement(User $user, DisputeCase $dispute, ProjectAssignment $assignment, Project $project): bool
    {
        return $this->view($user, $dispute, $assignment, $project);
    }

    public function resolve(User $user, DisputeCase $dispute, ProjectAssignment $assignment, Project $project): bool
    {
        return $this->viewAdmin($user, $dispute, $assignment, $project)
            && ! in_array($user->id, [$project->client_id, $assignment->freelancer_id, $dispute->opened_by], true);
    }

    public function download(User $user, DisputeCase $dispute, DeliverableVersion $version, ProjectAssignment $assignment, Project $project): bool
    {
        return $this->viewAdmin($user, $dispute, $assignment, $project)
            && $version->deliverable->project_assignment_id === $assignment->id;
    }
}
