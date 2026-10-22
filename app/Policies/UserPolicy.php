<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAdminAny(User $actor): bool
    {
        return $actor->isAdmin() && $actor->isActive();
    }

    public function viewAdmin(User $actor, User $account): bool
    {
        return $this->viewAdminAny($actor);
    }

    public function changeStatus(User $actor, User $account): bool
    {
        return $this->viewAdminAny($actor) && $actor->id !== $account->id && in_array($account->role, [User::ROLE_CLIENT, User::ROLE_FREELANCER], true);
    }

    public function viewPhoto(User $actor, User $account): bool
    {
        return $actor->is($account);
    }

    public function updatePhoto(User $actor, User $account): bool
    {
        return $actor->is($account);
    }
}
