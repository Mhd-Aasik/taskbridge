<?php

namespace App\Observers;

use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Support\Facades\DB;

class UserObserver
{
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
