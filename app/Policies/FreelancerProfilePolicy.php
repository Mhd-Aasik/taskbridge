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

    public function preview(User $user, FreelancerProfile $profile): bool
    {
        return $this->update($user, $profile);
    }

    public function publish(User $user, FreelancerProfile $profile): bool
    {
        return $this->update($user, $profile);
    }

    public function browse(User $user): bool
    {
        return $user->isClient();
    }

    public function view(User $user, FreelancerProfile $profile): bool
    {
        return $user->isClient() && FreelancerProfile::query()->visibleToClients()->whereKey($profile->id)->exists();
    }
}
