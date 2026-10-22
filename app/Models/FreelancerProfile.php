<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FreelancerProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FreelancerProfile extends Model
{
    /** @use HasFactory<FreelancerProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'headline',
        'bio',
        'location',
        'portfolio_url',
        'hourly_rate',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'hourly_rate' => 'decimal:2', // LKR, returned as a fixed-point string.
            'is_public' => 'boolean',
            'share_account_photo' => 'boolean',
            'first_published_at' => 'immutable_datetime',
            'profile_version' => 'integer',
        ];
    }

    protected function firstPublishedAt(): Attribute
    {
        return Attribute::make(set: fn ($value) => $value === null ? null : CarbonImmutable::parse($value, 'UTC')->utc()->format($this->getDateFormat()));
    }

    /** One eligibility rule for Client listings, details, proposal links and photos. */
    public function scopeVisibleToClients(Builder $query): void
    {
        $query->where('freelancer_profiles.is_public', true)
            ->whereHas('user', fn (Builder $owners) => $owners->where('role', User::ROLE_FREELANCER)->whereNull('suspended_at'));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Skill, $this> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class)->withTimestamps();
    }
}
