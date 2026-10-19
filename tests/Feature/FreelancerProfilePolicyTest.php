<?php

namespace Tests\Feature;

use App\Models\FreelancerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FreelancerProfilePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_freelancer_can_create_and_update_only_their_own_profile(): void
    {
        $profile = FreelancerProfile::factory()->create();
        $other = FreelancerProfile::factory()->create();

        $this->assertTrue(Gate::forUser($profile->user)->allows('create', FreelancerProfile::class));
        $this->assertTrue(Gate::forUser($profile->user)->allows('update', $profile));
        $this->assertFalse(Gate::forUser($profile->user)->allows('update', $other));
    }

    #[DataProvider('otherRoles')]
    public function test_non_freelancers_cannot_create_or_update_even_a_profile_they_own(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $profile = FreelancerProfile::factory()->create(['user_id' => $user->id]);

        $this->assertFalse(Gate::forUser($user)->allows('create', FreelancerProfile::class));
        $this->assertFalse(Gate::forUser($user)->allows('update', $profile));
    }

    public static function otherRoles(): array
    {
        return ['client' => [User::ROLE_CLIENT], 'admin' => [User::ROLE_ADMIN]];
    }
}
