<?php

namespace Tests\Feature;

use App\Models\FreelancerProfile;
use App\Models\Skill;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_skill_seeding_is_idempotent_and_creates_only_reference_data(): void
    {
        $this->seed(SkillSeeder::class);
        $initial = Skill::query()->orderBy('id')->get()->toArray();

        $this->travel(1)->hour();
        $this->seed(SkillSeeder::class);

        $this->assertSame($initial, Skill::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('skills', 8);
        $this->assertDatabaseHas('skills', ['name' => 'Laravel', 'slug' => 'laravel']);
        $this->assertDatabaseHas('skills', ['name' => 'UI/UX Design', 'slug' => 'uiux-design']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('freelancer_profiles', 0);
        $this->assertDatabaseCount('freelancer_profile_skill', 0);
    }

    public function test_seeding_preserves_existing_skills_profiles_and_assignments(): void
    {
        $profile = FreelancerProfile::factory()->create();
        $customSkill = Skill::query()->create(['name' => 'Custom Research']);
        $existingLaravel = Skill::query()->create(['name' => 'Existing Laravel label', 'slug' => 'laravel']);
        $profile->skills()->attach([$customSkill->id, $existingLaravel->id]);

        $this->seed(SkillSeeder::class);
        $this->seed(SkillSeeder::class);

        $this->assertDatabaseCount('skills', 9);
        $this->assertSame('Existing Laravel label', $existingLaravel->fresh()->name);
        $this->assertEqualsCanonicalizing([$customSkill->id, $existingLaravel->id], $profile->fresh()->skills->modelKeys());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('freelancer_profiles', 1);
        $this->assertDatabaseCount('freelancer_profile_skill', 2);
    }

    public function test_default_seeding_does_not_create_demo_accounts_or_profiles(): void
    {
        $user = User::factory()->create();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertModelExists($user);
        $this->assertDatabaseCount('freelancer_profiles', 0);
        $this->assertDatabaseCount('skills', 8);
    }
}
