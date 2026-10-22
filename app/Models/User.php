<?php

namespace App\Models;

use App\Services\AccountPhotos;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_CLIENT = 'client';

    public const ROLE_FREELANCER = 'freelancer';

    public const ROLE_ADMIN = 'admin';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'avatar_path',
        'suspended_at', 'suspended_by', 'suspension_reason', 'access_version',
    ];

    protected $attributes = ['access_version' => 0];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'avatar_path' => 'string',
            'suspended_at' => 'immutable_datetime',
            'suspended_by' => 'integer',
            'suspension_reason' => 'string',
            'access_version' => 'integer',
        ];
    }

    /** @return HasOne<FreelancerProfile, $this> */
    public function freelancerProfile(): HasOne
    {
        return $this->hasOne(FreelancerProfile::class);
    }

    /** @return MorphMany<InAppNotification, $this> */
    public function notifications(): MorphMany
    {
        return $this->morphMany(InAppNotification::class, 'notifiable')->latest()->orderByDesc('id');
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'client_id');
    }

    /**
     * All proposals authored by this account, regardless of status.
     *
     * @return HasMany<Proposal, $this>
     */
    public function submittedProposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'freelancer_id');
    }

    /** @return HasMany<ProjectAssignment, $this> */
    public function projectAssignments(): HasMany
    {
        return $this->hasMany(ProjectAssignment::class, 'freelancer_id');
    }

    /** @return HasMany<Milestone, $this> */
    public function createdMilestones(): HasMany
    {
        return $this->hasMany(Milestone::class, 'created_by');
    }

    /** @return HasMany<ProjectTask, $this> */
    public function createdTasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class, 'created_by');
    }

    /** @return HasMany<Deliverable, $this> */
    public function createdDeliverables(): HasMany
    {
        return $this->hasMany(Deliverable::class, 'created_by');
    }

    /** @return HasMany<DeliverableVersion, $this> */
    public function uploadedDeliverableVersions(): HasMany
    {
        return $this->hasMany(DeliverableVersion::class, 'uploaded_by');
    }

    /** @return HasMany<DeliverableReview, $this> */
    public function completedDeliverableReviews(): HasMany
    {
        return $this->hasMany(DeliverableReview::class, 'reviewed_by');
    }

    /** @return HasMany<Project, $this> */
    public function completedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'completed_by');
    }

    /** @return HasMany<Milestone, $this> */
    public function completedMilestones(): HasMany
    {
        return $this->hasMany(Milestone::class, 'completed_by');
    }

    /** @return HasMany<PaymentReport, $this> */
    public function reportedPayments(): HasMany
    {
        return $this->hasMany(PaymentReport::class, 'reported_by');
    }

    /** @return HasMany<PaymentAcknowledgment, $this> */
    public function paymentAcknowledgments(): HasMany
    {
        return $this->hasMany(PaymentAcknowledgment::class, 'acknowledged_by');
    }

    /** @return HasMany<DisputeCase, $this> */
    public function openedDisputes(): HasMany
    {
        return $this->hasMany(DisputeCase::class, 'opened_by');
    }

    /** @return HasMany<ProjectMessage, $this> */
    public function projectMessages(): HasMany
    {
        return $this->hasMany(ProjectMessage::class, 'sender_id');
    }

    /** @return HasMany<ProjectReadCursor, $this> */
    public function projectReadCursors(): HasMany
    {
        return $this->hasMany(ProjectReadCursor::class);
    }

    /** @return HasMany<DisputeStatement, $this> */
    public function disputeStatements(): HasMany
    {
        return $this->hasMany(DisputeStatement::class, 'submitted_by');
    }

    /** @return HasMany<DisputeResolution, $this> */
    public function disputeResolutions(): HasMany
    {
        return $this->hasMany(DisputeResolution::class, 'decided_by');
    }

    /** @return HasMany<ProjectRating, $this> */
    public function submittedProjectRatings(): HasMany
    {
        return $this->hasMany(ProjectRating::class, 'reviewer_id');
    }

    /** @return HasMany<ProjectRating, $this> */
    public function receivedProjectRatings(): HasMany
    {
        return $this->hasMany(ProjectRating::class, 'reviewee_id');
    }

    /** @return HasMany<AdminActionLog, $this> */
    public function adminActionLogs(): HasMany
    {
        return $this->hasMany(AdminActionLog::class, 'actor_id');
    }

    public function isClient(): bool
    {
        return $this->role === self::ROLE_CLIENT;
    }

    public function isActive(): bool
    {
        return $this->suspended_at === null;
    }

    /** @return HasMany<User, $this> */
    public function suspendedAccounts(): HasMany
    {
        return $this->hasMany(self::class, 'suspended_by');
    }

    /** @return BelongsTo<User, $this> */
    public function suspendedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'suspended_by');
    }

    public function isFreelancer(): bool
    {
        return $this->role === self::ROLE_FREELANCER;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function avatarUrl(): ?string
    {
        return AccountPhotos::ownsPath($this, $this->avatar_path)
            ? route('account.photo', ['user' => $this->getKey(), 'v' => hash('sha256', $this->avatar_path)])
            : null;
    }

    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }
}
