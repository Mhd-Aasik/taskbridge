<?php

namespace Tests\Feature;

use App\Models\FreelancerProfile;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FreelancerProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_create_a_private_profile_with_nullable_details(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $this->assertNull($user->freelancerProfile);

        $profile = $user->freelancerProfile()->create([])->refresh();

        $this->assertTrue($profile->user->is($user));
        $this->assertTrue($user->fresh()->freelancerProfile->is($profile));
        $this->assertFalse($profile->is_public);
        foreach (['headline', 'bio', 'location', 'portfolio_url', 'hourly_rate'] as $field) {
            $this->assertNull($profile->$field);
        }
        $this->assertNotNull($profile->created_at);
        $this->assertNotNull($profile->updated_at);
    }

    public function test_profile_ownership_cannot_be_mass_assigned(): void
    {
        $owner = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $other = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $profile = $owner->freelancerProfile()->create(['headline' => 'Laravel developer']);

        $profile->fill(['user_id' => $other->id, 'headline' => 'Updated headline'])->save();

        $this->assertFalse($profile->isFillable('user_id'));
        $this->assertSame($owner->id, $profile->fresh()->user_id);
        $this->assertSame('Updated headline', $profile->fresh()->headline);
        $this->assertNull($other->fresh()->freelancerProfile);
    }

    public function test_only_one_profile_is_allowed_per_user(): void
    {
        $profile = FreelancerProfile::factory()->create();

        $this->expectException(QueryException::class);
        FreelancerProfile::factory()->create(['user_id' => $profile->user_id]);
    }

    public function test_a_profile_must_reference_an_existing_user(): void
    {
        $this->expectException(QueryException::class);
        FreelancerProfile::factory()->create(['user_id' => 999999]);
    }

    #[DataProvider('rates')]
    public function test_hourly_rates_are_nullable_fixed_point_strings(?string $input, ?string $expected): void
    {
        $profile = FreelancerProfile::factory()->create(['hourly_rate' => $input]);

        $this->assertSame($expected, $profile->fresh()->hourly_rate);
        $this->assertSame($expected, $profile->fresh()->toArray()['hourly_rate']);
    }

    public function test_public_visibility_is_cast_to_a_boolean(): void
    {
        $profile = FreelancerProfile::factory()->create(['is_public' => 1]);
        $this->assertTrue($profile->fresh()->is_public);

        $profile->forceFill(['is_public' => 0])->save();
        $this->assertFalse($profile->fresh()->is_public);
        $this->assertFalse($profile->fresh()->toArray()['is_public']);
    }

    public function test_skills_can_be_assigned_and_retrieved_from_both_sides(): void
    {
        $profile = FreelancerProfile::factory()->create();
        $anotherProfile = FreelancerProfile::factory()->create();
        $skills = Skill::factory()->count(2)->create();
        $profile->skills()->attach($skills->modelKeys());
        $anotherProfile->skills()->attach($skills->first());

        $this->assertEqualsCanonicalizing($skills->modelKeys(), $profile->skills->modelKeys());
        $this->assertEqualsCanonicalizing([$profile->id, $anotherProfile->id], $skills->first()->freelancerProfiles->modelKeys());
        $this->assertNotNull($profile->skills->first()->pivot->created_at);
        $this->assertNotNull($profile->skills->first()->pivot->updated_at);

        $profile->skills()->sync([$skills->last()->id]);
        $this->assertSame([$skills->last()->id], $profile->fresh()->skills->modelKeys());
        $this->assertDatabaseCount('skills', 2);
    }

    public function test_a_skill_can_only_be_attached_once_per_profile(): void
    {
        $profile = FreelancerProfile::factory()->create();
        $skill = Skill::factory()->create();
        $profile->skills()->attach($skill);

        $this->expectException(QueryException::class);
        $profile->skills()->attach($skill);
    }

    public function test_pivot_rows_cannot_reference_missing_profiles(): void
    {
        $skill = Skill::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('freelancer_profile_skill')->insert(['freelancer_profile_id' => 999999, 'skill_id' => $skill->id]);
    }

    public function test_pivot_rows_cannot_reference_missing_skills(): void
    {
        $profile = FreelancerProfile::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('freelancer_profile_skill')->insert(['freelancer_profile_id' => $profile->id, 'skill_id' => 999999]);
    }

    public function test_deleting_a_user_cascades_to_the_profile_and_pivot_only(): void
    {
        $profile = FreelancerProfile::factory()->create();
        $skill = Skill::factory()->create();
        $profile->skills()->attach($skill);

        $profile->user->delete();

        $this->assertModelMissing($profile);
        $this->assertDatabaseCount('freelancer_profile_skill', 0);
        $this->assertModelExists($skill);
    }

    public function test_deleting_a_profile_preserves_its_user_and_skills(): void
    {
        $profile = FreelancerProfile::factory()->create();
        $user = $profile->user;
        $skill = Skill::factory()->create();
        $profile->skills()->attach($skill);

        $profile->delete();

        $this->assertDatabaseCount('freelancer_profile_skill', 0);
        $this->assertModelExists($user);
        $this->assertModelExists($skill);
    }

    public function test_the_profile_factory_creates_a_freelancer_owner(): void
    {
        $profile = FreelancerProfile::factory()->create();

        $this->assertTrue($profile->user->isFreelancer());
        $this->assertFalse($profile->is_public);
    }

    public static function rates(): array
    {
        return [
            'null' => [null, null],
            'zero' => ['0', '0.00'],
            'whole amount' => ['2500', '2500.00'],
            'fractional amount' => ['1250.50', '1250.50'],
            'small fraction' => ['0.01', '0.01'],
            'maximum decimal 12 2' => ['9999999999.99', '9999999999.99'],
        ];
    }
}
