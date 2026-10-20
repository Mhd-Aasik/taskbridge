<?php

namespace Tests\Feature\Settings;

use App\Models\FreelancerProfile;
use App\Models\Milestone;
use App\Models\Skill;
use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MilestoneAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('avatars');
    }

    #[DataProvider('roles')]
    public function test_milestone_authorship_blocks_deletion_before_cleanup_even_without_owned_projects(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        // Historical authorship can remain after project ownership or the author's role changes.
        $milestone = Milestone::factory()->create(['created_by' => $user->id])->fresh();
        $this->assertFalse($user->projects()->exists());
        $this->assertFalse($user->submittedProposals()->exists());
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('photo.png'));
        $profile = FreelancerProfile::factory()->create(['user_id' => $user->id, 'is_public' => false]);
        $skill = Skill::factory()->create();
        $profile->skills()->attach($skill);
        $userBefore = $user->fresh()->getAttributes();
        $profileBefore = $profile->fresh()->getAttributes();
        $milestoneBefore = $milestone->getAttributes();
        $photoBefore = Storage::disk('avatars')->get($user->avatar_path);

        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')
            ->assertHasErrors(['password'])->assertSee('Your account cannot be deleted because it has milestone history. Planning history must be preserved.')
            ->assertNoRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($userBefore, $user->fresh()->getAttributes());
        $this->assertSame($profileBefore, $profile->fresh()->getAttributes());
        $this->assertTrue($profile->fresh()->skills->sole()->is($skill));
        $this->assertSame($milestoneBefore, $milestone->fresh()->getAttributes());
        $this->assertModelExists($milestone->assignment);
        $this->assertSame($photoBefore, Storage::disk('avatars')->get($user->avatar_path));
    }

    #[DataProvider('roles')]
    public function test_users_without_domain_history_still_delete_and_clean_up_resources(string $role): void
    {
        $unrelated = Milestone::factory()->create();
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('photo.png'));
        $profile = FreelancerProfile::factory()->create(['user_id' => $user->id]);
        $skill = Skill::factory()->create();
        $profile->skills()->attach($skill);

        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');

        $this->assertGuest();
        $this->assertModelMissing($user);
        $this->assertModelMissing($profile);
        $this->assertDatabaseMissing('freelancer_profile_skill', ['freelancer_profile_id' => $profile->id]);
        Storage::disk('avatars')->assertMissing($user->avatar_path);
        $this->assertModelExists($unrelated);
        $this->assertModelExists($skill);
    }

    public static function roles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }
}
