<?php

namespace App\Models;

use App\Models\Concerns\ImmutableDisputeRecord;
use Carbon\CarbonImmutable;
use Database\Factories\DisputeCaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class DisputeCase extends Model
{
    /** @use HasFactory<DisputeCaseFactory> */
    use HasFactory, ImmutableDisputeRecord;

    protected $guarded = ['*'];

    protected $hidden = ['submission_id'];

    protected function casts(): array
    {
        return ['project_assignment_id' => 'integer', 'opened_by' => 'integer', 'title' => 'string', 'reason' => 'string', 'opened_at' => 'immutable_datetime', 'submission_id' => 'string'];
    }

    protected function openedAt(): Attribute
    {
        return Attribute::make(set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()));
    }

    /** @return BelongsTo<ProjectAssignment, $this> */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ProjectAssignment::class, 'project_assignment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return HasMany<DisputeStatement, $this> */
    public function statements(): HasMany
    {
        return $this->hasMany(DisputeStatement::class)->orderBy('submitted_at')->orderBy('id');
    }

    /** @return HasOne<DisputeResolution, $this> */
    public function resolution(): HasOne
    {
        return $this->hasOne(DisputeResolution::class);
    }

    /** @return HasOne<ActiveDispute, $this> */
    public function activeRecord(): HasOne
    {
        return $this->hasOne(ActiveDispute::class);
    }

    public function state(): string
    {
        return $this->resolution?->decision->value ?? ($this->activeRecord ? 'open' : 'unavailable');
    }

    public function isOpen(): bool
    {
        return $this->state() === 'open';
    }

    /** @param Builder<DisputeCase> $query */
    public function scopeForParticipant(Builder $query, User $user): void
    {
        $query->whereHas('assignment', function ($assignment) use ($user) {
            if ($user->isClient()) {
                $assignment->whereHas('project', fn ($project) => $project->where('client_id', $user->id));
            } elseif ($user->isFreelancer()) {
                $assignment->where('freelancer_id', $user->id);
            } else {
                $assignment->whereRaw('1 = 0');
            }
        });
    }

    /** @param Builder<DisputeCase> $query */
    public function scopeInState(Builder $query, string $state): void
    {
        if ($state === 'open') {
            $query->whereHas('activeRecord')->whereDoesntHave('resolution');
        } elseif (in_array($state, ['resolved', 'dismissed'], true)) {
            $query->whereHas('resolution', fn ($resolution) => $resolution->where('decision', $state));
        }
    }
}
