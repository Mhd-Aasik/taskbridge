<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Database\Factories\PaymentReportFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class PaymentReport extends Model
{
    /** @use HasFactory<PaymentReportFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $hidden = ['submission_id'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Recorded payment reports cannot be changed.');
        });
        static::deleting(function (): never {
            throw new LogicException('Payment history must be preserved.');
        });
    }

    protected function casts(): array
    {
        return [
            'project_assignment_id' => 'integer',
            'amount' => 'decimal:2',
            'currency' => 'string',
            'method' => PaymentMethod::class,
            'paid_on' => 'immutable_date:Y-m-d',
            'reference' => 'string',
            'note' => 'string',
            'reported_by' => 'integer',
            'reported_at' => 'immutable_datetime',
            'submission_id' => 'string',
        ];
    }

    protected function reportedAt(): Attribute
    {
        return Attribute::make(set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()));
    }

    /** @return BelongsTo<ProjectAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ProjectAssignment::class, 'project_assignment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** @return HasOne<PaymentAcknowledgment, $this> */
    public function acknowledgment(): HasOne
    {
        return $this->hasOne(PaymentAcknowledgment::class);
    }

    public function formattedAmount(): string
    {
        [$whole, $fraction] = explode('.', $this->amount, 2);

        return $this->currency.' '.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction;
    }
}
