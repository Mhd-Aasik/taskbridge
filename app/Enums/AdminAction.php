<?php

namespace App\Enums;

use App\Models\Category;
use App\Models\User;

enum AdminAction: string
{
    case CategoryCreated = 'category.created';
    case CategoryUpdated = 'category.updated';
    case CategoryActivated = 'category.activated';
    case CategoryDeactivated = 'category.deactivated';
    case AccountSuspended = 'account.suspended';
    case AccountReactivated = 'account.reactivated';

    public function subjectClass(): string
    {
        return match ($this) {
            self::AccountSuspended, self::AccountReactivated => User::class,
            default => Category::class,
        };
    }

    public function fields(bool $before = false): array
    {
        return match ($this) {
            self::CategoryCreated => $before ? [] : ['name', 'slug', 'description', 'is_active'],
            self::CategoryUpdated => ['name', 'description'],
            self::CategoryActivated, self::CategoryDeactivated => ['is_active'],
            self::AccountSuspended, self::AccountReactivated => $before ? ['status', 'suspended_at'] : ['status', 'suspended_at', 'reason'],
        };
    }
}
