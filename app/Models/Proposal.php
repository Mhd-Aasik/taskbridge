<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
