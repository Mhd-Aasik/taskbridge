<?php

namespace App\Actions\Profiles;

use App\Models\FreelancerProfile;
use App\Support\FreelancerProfileRules;
use App\Support\FreelancerProfiles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ChangeProfileVisibility
{
    public function publish(string $revision): FreelancerProfile
    {
        return $this->change('publish', $revision);
    }

    public function unpublish(string $revision): FreelancerProfile
    {
        return $this->change('unpublish', $revision);
    }

    public function photoConsent(bool $enabled, string $revision): FreelancerProfile
    {
        return $this->change($enabled ? 'share_photo' : 'hide_photo', $revision);
    }

    private function change(string $operation, string $revision): FreelancerProfile
    {
        return DB::transaction(function () use ($operation, $revision): FreelancerProfile {
            [$user, $profile] = FreelancerProfiles::owned(lock: true);
            if (! $profile) {
                throw ValidationException::withMessages(['visibility' => 'Save your professional profile before changing its visibility or photo preference.']);
            }
            Gate::forUser($user)->authorize('publish', $profile);

            $field = in_array($operation, ['publish', 'unpublish'], true) ? 'is_public' : 'share_account_photo';
            $desired = in_array($operation, ['publish', 'share_photo'], true);
            // Exact unchanged requests do not modify timestamps or the original publication time.
            if ($desired === $profile->$field && ($operation !== 'publish' || $profile->first_published_at !== null)) {
                return $profile;
            }
            if (! hash_equals(FreelancerProfiles::revision($profile), $revision)) {
                throw ValidationException::withMessages(['visibility' => 'Your saved profile changed. Reload and preview it before changing visibility or photo sharing.']);
            }
            if ($operation === 'publish') {
                FreelancerProfileRules::validate([
                    ...$profile->only(['headline', 'bio', 'location', 'portfolio_url', 'hourly_rate']),
                    'skill_ids' => $profile->skills()->pluck('skills.id')->all(),
                ]);
                $profile->first_published_at ??= CarbonImmutable::now('UTC');
            }
            $profile->$field = $desired;
            $profile->profile_version++;
            if (! $profile->saveOrFail()) {
                throw new RuntimeException('The profile preference could not be saved.');
            }

            return $profile;
        }, 3);
    }
}
