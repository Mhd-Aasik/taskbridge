<?php

namespace App\Models;

use App\Enums\AdminAction;
use Carbon\CarbonImmutable;
use Database\Factories\AdminActionLogFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

class AdminActionLog extends Model
{
    /** @use HasFactory<AdminActionLogFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $hidden = ['action_id', 'request_hash'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Administrative action logs are append-only.');
        });
        static::deleting(function (): never {
            throw new LogicException('Administrative action history must be preserved.');
        });
    }

    protected function casts(): array
    {
        return ['actor_id' => 'integer', 'subject_id' => 'integer', 'action' => AdminAction::class, 'before_values' => 'array', 'after_values' => 'array', 'occurred_at' => 'immutable_datetime'];
    }

    protected function occurredAt(): Attribute
    {
        return Attribute::make(set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()));
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
