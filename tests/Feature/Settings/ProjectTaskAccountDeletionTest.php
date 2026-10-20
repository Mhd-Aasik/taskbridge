<?php

namespace Tests\Feature\Settings;

use App\Models\FreelancerProfile;
use App\Models\ProjectTask;
use App\Models\Skill;
use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectTaskAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('avatars');
    }

    #[DataProvider('roles')]
    public function test_task_only_history_blocks_account_deletion_before_any_cleanup(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $task = ProjectTask::factory()->create(['created_by' => $user->id])->fresh();
        $this->assertFalse($user->projects()->exists());
        $this->assertFalse($user->submittedProposals()->exists());
        $this->assertFalse($user->createdMilestones()->exists());
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('photo.png'));
        $profile = FreelancerProfile::factory()->create(['user_id' => $user->id, 'is_public' => false]);
        $skill = Skill::factory()->create();
        $profile->skills()->attach($skill);
        $userBefore = $user->fresh()->getAttributes();
        $profileBefore = $profile->fresh()->getAttributes();
        $taskBefore = $task->getAttributes();
        $avatarBefore = Storage::disk('avatars')->get($user->avatar_path);

        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')
            ->assertHasErrors(['password'])->assertSee('Your account cannot be deleted because it has task history. Project history must be preserved.')
            ->assertNoRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($userBefore, $user->fresh()->getAttributes());
        $this->assertSame($profileBefore, $profile->fresh()->getAttributes());
        $this->assertTrue($profile->fresh()->skills->sole()->is($skill));
        $this->assertSame($taskBefore, $task->fresh()->getAttributes());
        $this->assertModelExists($task->assignment);
        $this->assertSame($avatarBefore, Storage::disk('avatars')->get($user->avatar_path));
    }

    #[DataProvider('roles')]
    public function test_accounts_without_history_still_delete_without_affecting_other_tasks(string $role): void
    {
        $unrelated = ProjectTask::factory()->create()->fresh();
        $before = $unrelated->getAttributes();
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
        Storage::disk('avatars')->assertMissing($user->avatar_path);
        $this->assertDatabaseMissing('freelancer_profile_skill', ['freelancer_profile_id' => $profile->id]);
        $this->assertSame($before, $unrelated->fresh()->getAttributes());
        $this->assertModelExists($skill);
    }

    public static function roles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }
}
