<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PaymentAcknowledgmentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PaymentAcknowledgment extends Model
{
    /** @use HasFactory<PaymentAcknowledgmentFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Recorded receipt acknowledgments cannot be changed.');
        });
        static::deleting(function (): never {
            throw new LogicException('Receipt history must be preserved.');
        });
    }

    protected function casts(): array
    {
        return ['payment_report_id' => 'integer', 'acknowledged_by' => 'integer', 'acknowledged_at' => 'immutable_datetime', 'note' => 'string'];
    }

    protected function acknowledgedAt(): Attribute
    {
        return Attribute::make(set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()));
    }

    /** @return BelongsTo<PaymentReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(PaymentReport::class, 'payment_report_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
