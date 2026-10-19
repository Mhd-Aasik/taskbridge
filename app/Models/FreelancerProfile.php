<?php

namespace App\Models;

use Database\Factories\FreelancerProfileFactory;
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
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'hourly_rate' => 'decimal:2', // LKR, returned as a fixed-point string.
            'is_public' => 'boolean',
        ];
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
