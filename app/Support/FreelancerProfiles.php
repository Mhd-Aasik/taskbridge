<?php

namespace App\Support;

use App\Models\FreelancerProfile;
use Illuminate\Support\Facades\Gate;

class FreelancerProfiles
{
    /** Account -> profile, shared by editing and visibility mutations. */
    public static function owned(bool $lock = false): array
    {
        $user = AccountAccess::account(lock: $lock);
        $profiles = $user->freelancerProfile();
        if ($lock) {
            $profiles->lockForUpdate();
        }
        $profile = $profiles->first();
        Gate::forUser($user)->authorize($profile ? 'update' : 'create', $profile ?? FreelancerProfile::class);

        return [$user, $profile];
    }

    public static function revision(FreelancerProfile $profile): string
    {
        // Normalize Eloquent casts: freshly saved booleans and reloaded SQL integers
        // must describe the same saved snapshot on both SQLite and MySQL.
        $attributes = $profile->only(['id', 'user_id', 'headline', 'bio', 'location', 'portfolio_url', 'hourly_rate', 'is_public', 'share_account_photo', 'first_published_at', 'profile_version', 'created_at', 'updated_at']);

        return hash('sha256', json_encode([
            $attributes,
            $profile->skills()->orderBy('skills.id')->pluck('skills.id')->all(),
        ], JSON_THROW_ON_ERROR));
    }
}
