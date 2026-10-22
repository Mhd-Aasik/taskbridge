<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AccountDeletion
{
    /** Caller must hold the transaction until deletion commits, before any avatar cleanup. */
    public static function lockAccount(): User
    {
        $candidate = User::query()->findOrFail(Auth::id());
        if ($candidate->isAdmin()) {
            // Lock all active Admins in ID order before the actor; never actor-first here.
            // A second deletion re-reads the surviving set after waiting for the first.
            User::query()->where('role', User::ROLE_ADMIN)->whereNull('suspended_at')->orderBy('id')->lockForUpdate()->get(['id']);
        }
        $user = User::query()->lockForUpdate()->findOrFail(Auth::id());
        AccountAccess::ensure($user);
        if ($user->adminActionLogs()->exists()) {
            throw ValidationException::withMessages(['password' => 'Your account cannot be deleted because it has administrative action history. Audit records must be preserved.']);
        }
        if ($user->suspendedAccounts()->exists() || AccountManagement::history($user)->exists()) {
            throw ValidationException::withMessages(['password' => 'Your account cannot be deleted because it has administrative account-status history. These records must be preserved.']);
        }

        return $user;
    }

    public static function ensureAdministratorRemains(User $user): void
    {
        if ($user->isAdmin() && User::query()->where('role', User::ROLE_ADMIN)->whereNull('suspended_at')->orderBy('id')->lockForUpdate()->get(['id'])->count() <= 1) {
            throw ValidationException::withMessages(['password' => 'The last active administrator cannot delete their account. Another active administrator must remain.']);
        }
    }
}
