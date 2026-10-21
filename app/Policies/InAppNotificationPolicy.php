<?php

namespace App\Policies;

use App\Models\InAppNotification;
use App\Models\User;

class InAppNotificationPolicy
{
    public function view(User $user, InAppNotification $notification): bool
    {
        return $notification->notifiable_type === $user->getMorphClass() && $notification->notifiable_id === $user->id;
    }

    public function update(User $user, InAppNotification $notification): bool
    {
        return $this->view($user, $notification);
    }
}
