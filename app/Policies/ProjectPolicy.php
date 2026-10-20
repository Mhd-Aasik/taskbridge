<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function browse(User $user): bool
    {
        return $user->isFreelancer();
    }

    public function viewAny(User $user): bool
    {
        return $user->isClient();
    }

    public function create(User $user): bool
    {
        return $user->isClient();
    }

    public function view(User $user, Project $project): bool
    {
        return $user->isClient() && $project->client_id === $user->id;
    }

    public function update(User $user, Project $project): bool
    {
        return $this->view($user, $project) && $project->status === ProjectStatus::Draft;
    }

    public function publish(User $user, Project $project): bool
    {
        return $this->update($user, $project) && $project->getRawOriginal('published_at') === null;
    }
}
