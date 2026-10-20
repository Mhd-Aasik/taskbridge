<?php

namespace App\Models;

use App\Services\AccountPhotos;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'avatar_path' => 'string',
        ];
    }

    /** @return HasOne<FreelancerProfile, $this> */
    public function freelancerProfile(): HasOne
    {
        return $this->hasOne(FreelancerProfile::class);
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

    public function isClient(): bool
    {
        return $this->role === self::ROLE_CLIENT;
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
