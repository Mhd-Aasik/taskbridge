<?php

namespace App\Support;

use App\Enums\AdminAction;
use App\Models\AdminActionLog;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AccountManagement
{
    public const LIST_FIELDS = ['id', 'name', 'email', 'role', 'created_at', 'suspended_at'];

    public const STATUS_FIELDS = ['id', 'name', 'email', 'role', 'created_at', 'suspended_at', 'suspended_by', 'suspension_reason', 'access_version'];

    public static function admin(bool $lock = false): User
    {
        $actor = AccountAccess::account($lock);
        Gate::forUser($actor)->authorize('viewAdminAny', User::class);

        return $actor;
    }

    public static function version(User $account): string
    {
        return hash('sha256', json_encode([$account->id, $account->name, $account->email, $account->role, $account->getRawOriginal('suspended_at'), $account->suspended_by, $account->suspension_reason, $account->access_version], JSON_THROW_ON_ERROR));
    }

    public static function snapshot(User $account): array
    {
        return ['status' => $account->isActive() ? 'active' : 'suspended', 'suspended_at' => $account->suspended_at?->utc()->format('Y-m-d H:i:s')];
    }

    public static function history(User $account)
    {
        return AdminActionLog::query()->where('subject_type', $account->getMorphClass())->where('subject_id', $account->id)
            ->whereIn('action', [AdminAction::AccountSuspended->value, AdminAction::AccountReactivated->value]);
    }
}
