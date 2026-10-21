<?php

namespace App\Enums;

enum DisputeDecision: string
{
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Resolved => 'Resolved', self::Dismissed => 'Dismissed'
        };
    }
}
