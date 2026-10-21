<?php

namespace App\Models;

use App\Enums\DisputeDecision;
use App\Models\Concerns\ImmutableDisputeRecord;
use Carbon\CarbonImmutable;
use Database\Factories\DisputeResolutionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisputeResolution extends Model
{
    /** @use HasFactory<DisputeResolutionFactory> */
    use HasFactory, ImmutableDisputeRecord;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['dispute_case_id' => 'integer', 'decided_by' => 'integer', 'decision' => DisputeDecision::class, 'rationale' => 'string', 'decided_at' => 'immutable_datetime'];
    }

    protected function decidedAt(): Attribute
    {
        return Attribute::make(set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()));
    }

    /** @return BelongsTo<DisputeCase, $this> */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(DisputeCase::class, 'dispute_case_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
