<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;

class ProposalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isFreelancer();
    }

    public function view(User $user, Proposal $proposal): bool
    {
        return $user->isFreelancer() && $proposal->freelancer_id === $user->id;
    }

    public function create(User $user, Project $project): bool
    {
        return $user->isFreelancer() && $project->client_id !== $user->id;
    }
}
