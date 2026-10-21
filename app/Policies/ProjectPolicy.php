<?php

namespace App\Policies;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\ProjectAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

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

    public function complete(User $user, Project $project, ProjectAssignment $assignment): bool
    {
        return $assignment->project_id === $project->id && $this->view($user, $project);
    }

    public function publish(User $user, Project $project): bool
    {
        return $this->update($user, $project) && $project->getRawOriginal('published_at') === null;
    }

    public function viewCover(User $user, Project $project): bool
    {
        // Re-read the role for direct and long-lived authenticated requests.
        $user = User::query()->find($user->id);
        if (! $user) {
            return false;
        }
        if ($this->view($user, $project)) {
            return true;
        }
        if (! $user->isFreelancer()) {
            return false;
        }
        if ($project->newQuery()->whereKey($project->id)->discoverable()->exists()) {
            return true;
        }
        $assignment = $project->assignment()->where('freelancer_id', $user->id)->first();
        if ($assignment && Gate::forUser($user)->allows('view', $assignment)) {
            return true;
        }
        $proposal = $project->proposals()->where('freelancer_id', $user->id)->first();

        return $proposal && Gate::forUser($user)->allows('view', $proposal);
    }
}
