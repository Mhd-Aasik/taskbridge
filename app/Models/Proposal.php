<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Proposal extends Model
{
    /** @use HasFactory<ProposalFactory> */
    use HasFactory;

    protected $fillable = ['cover_letter', 'proposed_budget', 'proposed_deadline'];

    protected $attributes = ['status' => ProposalStatus::Submitted->value];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'freelancer_id' => 'integer',
            'cover_letter' => 'string',
            'proposed_budget' => 'decimal:2', // LKR, kept as a decimal string.
            'proposed_deadline' => 'immutable_date:Y-m-d',
            'status' => ProposalStatus::class,
            'submitted_at' => 'immutable_datetime',
        ];
    }

    protected function submittedAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()),
        );
    }

    public function formattedBudget(): string
    {
        $raw = (string) $this->getRawOriginal('proposed_budget');
        if (! preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', $raw)) {
            return 'Budget needs correction.';
        }
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';

        return 'LKR '.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.str_pad($fraction, 2, '0');
    }

    public function calendarDeadline(): ?CarbonImmutable
    {
        $raw = (string) $this->getRawOriginal('proposed_deadline');
        if (! preg_match('/\A(\d{4})-(\d{2})-(\d{2})(?: 00:00:00)?\z/', $raw, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', substr($raw, 0, 10), 'Asia/Colombo');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'freelancer_id');
    }

    /** @return HasOne<ProjectAssignment, $this> */
    public function assignment(): HasOne
    {
        return $this->hasOne(ProjectAssignment::class);
    }
}
