<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AccountAccess
{
    public const SESSION_KEY = 'taskbridge.account_access';

    public const UNAVAILABLE = 'Your account is currently unavailable. Contact TaskBridge support for assistance.';

    public const EXPIRED = 'Your session has ended. Please log in again.';

    /** No global User scope: suspended authors must remain available in history. */
    public static function allows(User $actor, bool $fresh = false): bool
    {
        if (! $actor->isActive()) {
            return false;
        }
        // Only the web middleware supplies a validated request snapshot. It avoids
        // repeating actor queries for every card/policy while rendering a page.
        // Mutation contexts always bypass it after obtaining their account locks.
        $current = $fresh ? null : request()->attributes->get('taskbridge.available_actor');
        if (! $current instanceof User || $current->id !== $actor->id || $current->getConnection()->getName() !== $actor->getConnection()->getName()) {
            $current = User::on($actor->getConnectionName())->select(['id', 'suspended_at', 'access_version'])->find($actor->id);
        }
        if (! $current || ! $current->isActive() || (int) $actor->access_version !== $current->access_version) {
            return false;
        }
        $authenticated = Auth::user();
        if ($authenticated instanceof User && $authenticated->id === $actor->id) {
            if ((int) $authenticated->access_version !== $current->access_version) {
                return false;
            }
            $stamp = request()->hasSession() ? request()->session()->get(self::SESSION_KEY) : null;
            if ($stamp !== null && (! is_array($stamp) || (($stamp['id'] ?? null) === $actor->id ? ($stamp['version'] ?? null) !== $current->access_version : $current->access_version !== 0))) {
                return false;
            }
        }

        return true;
    }

    public static function ensure(User $actor): void
    {
        abort_unless(self::allows($actor, fresh: true), 403, self::UNAVAILABLE);
    }

    public static function account(bool $lock = false): User
    {
        $query = User::query()->whereKey(Auth::id());
        if ($lock) {
            $query->lockForUpdate();
        }
        $actor = $query->first();
        abort_unless($actor, 403);
        self::ensure($actor);

        return $actor;
    }
}
