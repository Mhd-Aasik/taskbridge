<?php

namespace App\Policies;

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
}
