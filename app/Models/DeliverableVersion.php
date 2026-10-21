<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DeliverableVersionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class DeliverableVersion extends Model
{
    /** @use HasFactory<DeliverableVersionFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $hidden = ['storage_path', 'submission_id'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Submitted deliverable versions cannot be changed. Upload a new version instead.');
        });
        static::deleting(function (): never {
            throw new LogicException('Submitted deliverable versions must be preserved.');
        });
    }

    protected function casts(): array
    {
        return [
            'deliverable_id' => 'integer',
            'version_number' => 'integer',
            'uploaded_by' => 'integer',
            'storage_path' => 'string',
            'original_filename' => 'string',
            'mime_type' => 'string',
            'byte_size' => 'integer',
            'sha256' => 'string',
            'submission_note' => 'string',
            'submission_id' => 'string',
            'submitted_at' => 'immutable_datetime',
        ];
    }

    protected function submittedAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()),
        );
    }

    /** @return BelongsTo<Deliverable, $this> */
    public function deliverable(): BelongsTo
    {
        return $this->belongsTo(Deliverable::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return HasOne<DeliverableReview, $this> */
    public function review(): HasOne
    {
        return $this->hasOne(DeliverableReview::class);
    }
}
