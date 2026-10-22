<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;

class ProjectActivityPolicy
{
    public function viewAny(User $user, Project $project, ?ProjectAssignment $assignment = null): bool
    {
        return ($user->isClient() && $project->client_id === $user->id)
            || ($user->isFreelancer() && $assignment?->project_id === $project->id && $assignment->freelancer_id === $user->id);
    }
}
