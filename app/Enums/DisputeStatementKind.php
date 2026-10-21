<?php

namespace App\Enums;

enum DisputeStatementKind: string
{
    case Statement = 'statement';
    case Clarification = 'clarification';

    public function label(): string
    {
        return match ($this) {
            self::Statement => 'Participant statement', self::Clarification => 'Admin clarification request'
        };
    }
}
