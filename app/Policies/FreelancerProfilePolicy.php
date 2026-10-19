<?php

namespace App\Policies;

use App\Models\FreelancerProfile;
use App\Models\User;

class FreelancerProfilePolicy
{
    public function create(User $user): bool
    {
        return $user->isFreelancer();
    }

    public function update(User $user, FreelancerProfile $profile): bool
    {
        return $user->isFreelancer() && $profile->user_id === $user->id;
    }
}
