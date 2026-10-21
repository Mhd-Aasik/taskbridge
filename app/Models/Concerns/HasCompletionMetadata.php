<?php

namespace App\Models\Concerns;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait HasCompletionMetadata
{
    protected static function bootHasCompletionMetadata(): void
    {
        static::updating(function (Model $record): void {
            if ($record->getRawOriginal('completed_at') !== null && $record->isDirty(['completed_at', 'completed_by', 'completion_note'])) {
                throw new LogicException('Recorded completion metadata cannot be changed.');
            }
        });
    }

    protected function completedAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()),
        );
    }

    /** @return BelongsTo<User, $this> */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
