<?php

namespace App\Models\Concerns;

use LogicException;

trait ImmutableDisputeRecord
{
    protected static function bootImmutableDisputeRecord(): void
    {
        static::updating(function (): never {
            throw new LogicException('Recorded dispute history cannot be changed.');
        });
        static::deleting(function (): never {
            throw new LogicException('Recorded dispute history must be preserved.');
        });
    }
}
