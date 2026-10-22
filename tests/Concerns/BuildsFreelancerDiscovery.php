<?php

namespace Tests\Concerns;

use App\Models\FreelancerProfile;
use App\Models\Skill;
use App\Models\User;
use Carbon\CarbonImmutable;

trait BuildsFreelancerDiscovery
{
    private function professionalProfile(array $attributes = [], ?array $skills = null): FreelancerProfile
    {
        $profile = FreelancerProfile::factory()->create([
            'headline' => 'Laravel specialist', 'bio' => 'Thoughtful applications and accessible interfaces.',
            'location' => 'Colombo', 'portfolio_url' => 'https://example.com/work', 'hourly_rate' => '2500.50',
            ...$attributes,
        ]);
        $profile->skills()->attach($skills ?? [Skill::factory()->create()->id]);

        return $profile->fresh();
    }

    private function visibleProfile(array $attributes = [], ?array $skills = null): FreelancerProfile
    {
        return $this->professionalProfile(['is_public' => true, 'first_published_at' => CarbonImmutable::now('UTC'), ...$attributes], $skills);
    }

    private function discoveryClient(): User
    {
        $user = User::factory()->create(['role' => User::ROLE_CLIENT]);
        $this->actingAs($user);

        return $user;
    }
}
