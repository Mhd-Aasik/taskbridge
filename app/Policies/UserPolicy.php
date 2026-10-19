<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewPhoto(User $actor, User $account): bool
    {
        return $actor->is($account);
    }

    public function updatePhoto(User $actor, User $account): bool
    {
        return $actor->is($account);
    }
}
