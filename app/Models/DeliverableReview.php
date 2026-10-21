<?php

namespace App\Models;

use App\Enums\DeliverableReviewDecision;
use Carbon\CarbonImmutable;
use Database\Factories\DeliverableReviewFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DeliverableReview extends Model
{
    /** @use HasFactory<DeliverableReviewFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Completed deliverable reviews cannot be changed.');
        });
        static::deleting(function (): never {
            throw new LogicException('Completed deliverable reviews must be preserved.');
        });
    }

    protected function casts(): array
    {
        return [
            'deliverable_version_id' => 'integer',
            'reviewed_by' => 'integer',
            'decision' => DeliverableReviewDecision::class,
            'remarks' => 'string',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    protected function reviewedAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()),
        );
    }

    /** @return BelongsTo<DeliverableVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DeliverableVersion::class, 'deliverable_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
