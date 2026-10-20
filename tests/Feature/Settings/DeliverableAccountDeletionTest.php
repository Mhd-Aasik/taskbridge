<?php

namespace Tests\Feature\Settings;

use App\Models\Deliverable;
use App\Models\DeliverableVersion;
use App\Models\FreelancerProfile;
use App\Models\Skill;
use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeliverableAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('avatars');
        Storage::fake('deliverables');
    }

    #[DataProvider('historyAndRoles')]
    public function test_each_deliverable_history_kind_prevents_cleanup_for_every_account_role(string $history, string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $deliverable = Deliverable::factory()->create($history === 'creator' ? ['created_by' => $user->id] : []);
        $version = DeliverableVersion::factory()->create(['deliverable_id' => $deliverable->id] + ($history === 'uploader' ? ['uploaded_by' => $user->id] : []));
        Storage::disk('deliverables')->put($version->storage_path, 'Private submitted work.');
        $this->assertFalse($user->projects()->exists());
        $this->assertFalse($user->submittedProposals()->exists());
        $this->assertFalse($user->createdMilestones()->exists());
        $this->assertFalse($user->createdTasks()->exists());
        $this->assertSame($history === 'creator', $user->createdDeliverables()->exists());
        $this->assertSame($history === 'uploader', $user->uploadedDeliverableVersions()->exists());
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('photo.png'));
        $profile = FreelancerProfile::factory()->create(['user_id' => $user->id, 'is_public' => false]);
        $skill = Skill::factory()->create();
        $profile->skills()->attach($skill);
        $userBefore = $user->fresh()->getAttributes();
        $profileBefore = $profile->fresh()->getAttributes();
        $deliverableBefore = $deliverable->fresh()->getAttributes();
        $versionBefore = $version->fresh()->getAttributes();
        $photoBefore = Storage::disk('avatars')->get($user->avatar_path);

        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')
            ->assertHasErrors(['password'])
            ->assertSee('Your account cannot be deleted because it has deliverable history. Submitted files and project history must be preserved.')
            ->assertNoRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($userBefore, $user->fresh()->getAttributes());
        $this->assertSame($profileBefore, $profile->fresh()->getAttributes());
        $this->assertTrue($profile->fresh()->skills->sole()->is($skill));
        $this->assertModelExists($skill);
        $this->assertSame($deliverableBefore, $deliverable->fresh()->getAttributes());
        $this->assertSame($versionBefore, $version->fresh()->getAttributes());
        $this->assertSame($photoBefore, Storage::disk('avatars')->get($user->avatar_path));
        $this->assertSame('Private submitted work.', Storage::disk('deliverables')->get($version->storage_path));
    }

    #[DataProvider('roles')]
    public function test_accounts_without_history_can_delete_without_affecting_unrelated_versions(string $role): void
    {
        $unrelated = DeliverableVersion::factory()->create()->fresh();
        $before = $unrelated->getAttributes();
        Storage::disk('deliverables')->put($unrelated->storage_path, 'Unrelated immutable delivery.');
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
        $this->assertSame($before, $unrelated->fresh()->getAttributes());
        $this->assertSame('Unrelated immutable delivery.', Storage::disk('deliverables')->get($unrelated->storage_path));
        $this->assertModelExists($unrelated->deliverable);
        $this->assertModelExists($skill);
    }

    public static function roles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function historyAndRoles(): array
    {
        $cases = [];
        foreach (['creator', 'uploader'] as $history) {
            foreach ([User::ROLE_CLIENT, User::ROLE_FREELANCER, User::ROLE_ADMIN] as $role) {
                $cases[] = [$history, $role];
            }
        }

        return $cases;
    }
}
