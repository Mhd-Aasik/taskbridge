<?php

namespace App\Models;

use App\Enums\DisputeStatementKind;
use App\Models\Concerns\ImmutableDisputeRecord;
use Carbon\CarbonImmutable;
use Database\Factories\DisputeStatementFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisputeStatement extends Model
{
    /** @use HasFactory<DisputeStatementFactory> */
    use HasFactory, ImmutableDisputeRecord;

    protected $guarded = ['*'];

    protected $hidden = ['submission_id'];

    protected function casts(): array
    {
        return ['dispute_case_id' => 'integer', 'submitted_by' => 'integer', 'kind' => DisputeStatementKind::class, 'body' => 'string', 'submitted_at' => 'immutable_datetime', 'submission_id' => 'string'];
    }

    protected function submittedAt(): Attribute
    {
        return Attribute::make(set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()));
    }

    /** @return BelongsTo<DisputeCase, $this> */
    public function dispute(): BelongsTo
    {
        return $this->belongsTo(DisputeCase::class, 'dispute_case_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
