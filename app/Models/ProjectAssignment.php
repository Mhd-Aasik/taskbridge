<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ProjectAssignmentFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProjectAssignment extends Model
{
    /** @use HasFactory<ProjectAssignmentFactory> */
    use HasFactory;

    // All identities, terms, and timestamps are assigned by trusted application code.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'proposal_id' => 'integer',
            'freelancer_id' => 'integer',
            'agreed_budget' => 'decimal:2',
            'agreed_deadline' => 'immutable_date:Y-m-d',
            'assigned_at' => 'immutable_datetime',
        ];
    }

    protected function assignedAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()),
        );
    }

    public function formattedBudget(): string
    {
        [$whole, $fraction] = explode('.', $this->agreed_budget, 2);

        return 'LKR '.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction;
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Proposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'freelancer_id');
    }

    /** @return HasMany<Milestone, $this> */
    public function milestones(): HasMany
    {
        return $this->hasMany(Milestone::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<ProjectTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class);
    }

    /** @return HasMany<Deliverable, $this> */
    public function deliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class)->orderBy('id');
    }

    /** @return HasOne<PaymentReport, $this> */
    public function paymentReport(): HasOne
    {
        return $this->hasOne(PaymentReport::class);
    }
}
