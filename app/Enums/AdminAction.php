<?php

namespace App\Enums;

enum AdminAction: string
{
    case CategoryCreated = 'category.created';
    case CategoryUpdated = 'category.updated';
    case CategoryActivated = 'category.activated';
    case CategoryDeactivated = 'category.deactivated';

    public function fields(bool $before = false): array
    {
        return match ($this) {
            self::CategoryCreated => $before ? [] : ['name', 'slug', 'description', 'is_active'],
            self::CategoryUpdated => ['name', 'description'],
            self::CategoryActivated, self::CategoryDeactivated => ['is_active'],
        };
    }
}
