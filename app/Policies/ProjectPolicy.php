<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isClient();
    }

    public function create(User $user): bool
    {
        return $user->isClient();
    }

    public function update(User $user, Project $project): bool
    {
        return $user->isClient()
            && $project->client_id === $user->id
            && $project->status === ProjectStatus::Draft;
    }
}
