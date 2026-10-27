<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AccountPhotos;
use App\Services\AccountSessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserObserver
{
    public function updating(User $user): void
    {
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
            $user->remember_token = Str::random(60);
            $user->access_version = (int) $user->getOriginal('access_version') + 1;
        }
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('email')) {
            app(AccountSessions::class)->revoke($user);
        }
    }

    public function deleting(User $user): void
    {
        $user->avatar_path = User::query()->whereKey($user->getKey())->value('avatar_path');
    }

    public function deleted(User $user): void
    {
        $path = $user->avatar_path;
        DB::afterCommit(fn () => app(AccountPhotos::class)->deleteOwnedFile($user, $path));
    }
}
