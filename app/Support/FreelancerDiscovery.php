<?php

namespace App\Support;

use App\Models\FreelancerProfile;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FreelancerDiscovery
{
    /** Never select private account columns or feedback text for Client presentation. */
    public static function query(bool $eligible = true): Builder
    {
        $query = FreelancerProfile::query();
        if ($eligible) {
            $query->visibleToClients();
        }

        return $query->join('users as profile_owner', 'profile_owner.id', '=', 'freelancer_profiles.user_id')
            ->leftJoinSub(ProjectReputation::aggregateQuery(User::ROLE_FREELANCER), 'profile_reputation', 'profile_reputation.reviewee_id', '=', 'freelancer_profiles.user_id')
            ->select([
                'freelancer_profiles.id', 'freelancer_profiles.user_id', 'headline', 'bio', 'location', 'portfolio_url',
                'hourly_rate', 'share_account_photo', 'first_published_at', 'profile_owner.name as display_name',
                'profile_reputation.rating_count', 'profile_reputation.rating_average',
            ])->with('skills:id,name');
    }

    /** Explicit presentation whitelist; only this array crosses the Client view boundary. */
    public static function present(FreelancerProfile $profile, bool $preview = false): array
    {
        $portfolio = $profile->portfolio_url;
        if (Validator::make(['url' => $portfolio], ['url' => ['nullable', 'string', 'max:2048', 'url:http,https']])->fails()) {
            $portfolio = null;
        }
        $rate = $profile->hourly_rate;
        if ($rate !== null) {
            [$whole, $fraction] = explode('.', (string) BigDecimal::of($rate)->toScale(2));
            $rate = 'LKR '.preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole).'.'.$fraction;
        }

        return [
            'id' => $profile->id,
            'name' => $profile->display_name,
            'initials' => Str::of($profile->display_name)->explode(' ')->filter()->take(2)->map(fn ($part) => Str::upper(Str::substr($part, 0, 1)))->implode(''),
            'headline' => $profile->headline,
            'bio' => $profile->bio,
            'location' => $profile->location,
            'hourly_rate' => $profile->hourly_rate,
            'formatted_rate' => $rate,
            'portfolio_url' => $portfolio,
            'skills' => $profile->skills->map(fn ($skill) => $skill->only(['id', 'name']))->all(),
            'reputation' => ['count' => (int) $profile->rating_count, 'average' => $profile->rating_count ? number_format((float) $profile->rating_average, 1, '.', '') : null, 'role' => User::ROLE_FREELANCER],
            'photo_url' => $profile->share_account_photo ? ($preview ? route('account.photo', $profile->user_id) : route('client.freelancers.photo', $profile->id)) : null,
        ];
    }

    public static function literalPattern(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }
}
