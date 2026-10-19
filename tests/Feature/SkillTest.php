<?php

namespace Tests\Feature;

use App\Models\FreelancerProfile;
use App\Models\Skill;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillTest extends TestCase
{
    use RefreshDatabase;

    public function test_slugs_are_generated_from_skill_names(): void
    {
        $skill = Skill::query()->create(['name' => 'UI/UX Design']);

        $this->assertSame('uiux-design', $skill->fresh()->slug);
        $this->assertSame('UI/UX Design', $skill->fresh()->name);
        $this->assertNotNull($skill->created_at);
        $this->assertNotNull($skill->updated_at);
    }

    public function test_explicit_slugs_are_normalised_when_created_and_updated(): void
    {
        $skill = Skill::query()->create(['name' => 'Web Design', 'slug' => '  WEB__Design  ']);
        $this->assertSame('web-design', $skill->fresh()->slug);

        $skill->update(['slug' => ' FRONTEND / Design ']);
        $this->assertSame('frontend-design', $skill->fresh()->slug);
    }

    public function test_renaming_a_skill_preserves_its_existing_slug(): void
    {
        $skill = Skill::query()->create(['name' => 'Web Design']);
        $skill->update(['name' => 'Website Design']);

        $this->assertSame('web-design', $skill->fresh()->slug);
    }

    public function test_normalised_slugs_must_be_unique(): void
    {
        Skill::query()->create(['name' => 'Web Design']);

        $this->expectException(QueryException::class);
        Skill::query()->create(['name' => 'Another name', 'slug' => ' WEB__DESIGN ']);
    }

    public function test_deleting_a_skill_removes_only_its_pivot_rows(): void
    {
        $profiles = FreelancerProfile::factory()->count(2)->create();
        $skill = Skill::factory()->create();
        $retainedSkill = Skill::factory()->create();
        foreach ($profiles as $profile) {
            $profile->skills()->attach([$skill->id, $retainedSkill->id]);
        }

        $skill->delete();

        $this->assertDatabaseCount('freelancer_profile_skill', 2);
        $this->assertDatabaseCount('freelancer_profiles', 2);
        $this->assertDatabaseCount('users', 2);
        foreach ($profiles as $profile) {
            $this->assertSame([$retainedSkill->id], $profile->fresh()->skills->modelKeys());
        }
        $this->assertModelExists($retainedSkill);
    }
}
