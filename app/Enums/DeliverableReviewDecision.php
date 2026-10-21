<?php

namespace App\Enums;

enum DeliverableReviewDecision: string
{
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::ChangesRequested => 'Changes requested',
        };
    }
}
