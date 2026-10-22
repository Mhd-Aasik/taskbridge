<?php

namespace Tests\Feature\Settings;

use App\Enums\ProposalStatus;
use App\Models\FreelancerProfile;
use App\Models\Proposal;
use App\Models\Skill;
use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProposalAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('avatars');
        Storage::fake('local');
        Storage::fake('public');
    }

    #[DataProvider('statuses')]
    public function test_proposal_history_blocks_deletion_before_any_account_cleanup(ProposalStatus $status): void
    {
        $proposal = Proposal::factory()->create(['status' => $status]);
        $user = $proposal->freelancer;
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('photo.png'));
        $profile = FreelancerProfile::factory()->create(['user_id' => $user->id, 'is_public' => true]);
        $skill = Skill::factory()->create();
        $profile->skills()->attach($skill);
        $userBefore = $user->fresh()->getAttributes();
        $profileBefore = $profile->fresh()->getAttributes();
        $proposalBefore = $proposal->fresh()->getAttributes();
        $projectBefore = $proposal->project->getAttributes();
        $photoBefore = Storage::disk('avatars')->get($user->avatar_path);

        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')
            ->assertHasErrors(['password'])
            ->assertSee('Your account cannot be deleted because it has proposals. Proposal history must be preserved.')
            ->assertNoRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($userBefore, $user->fresh()->getAttributes());
        $this->assertSame($profileBefore, $profile->fresh()->getAttributes());
        $this->assertTrue($profile->fresh()->skills->sole()->is($skill));
        $this->assertSame($proposalBefore, $proposal->fresh()->getAttributes());
        $this->assertSame($projectBefore, $proposal->project->fresh()->getAttributes());
        $this->assertSame($photoBefore, Storage::disk('avatars')->get($user->avatar_path));
    }

    #[DataProvider('changedRoles')]
    public function test_history_is_protected_if_the_freelancers_role_changes(string $role): void
    {
        $proposal = Proposal::factory()->create();
        $user = $proposal->freelancer;
        $user->role = $role;
        $user->save();
        $this->actingAs($user);

        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')
            ->assertHasErrors(['password'])->assertSee('Proposal history must be preserved.')->assertNoRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertModelExists($proposal);
    }

    public function test_incorrect_password_cannot_delete_an_account_with_proposal_history(): void
    {
        $proposal = Proposal::factory()->create();
        $this->actingAs($proposal->freelancer);

        Volt::test('settings.delete-user-form')->set('password', 'incorrect')->call('deleteUser')
            ->assertHasErrors(['password' => 'current_password'])->assertNoRedirect();

        $this->assertModelExists($proposal->freelancer);
        $this->assertModelExists($proposal);
    }

    #[DataProvider('roles')]
    public function test_accounts_without_project_or_proposal_history_still_delete_and_clean_up_resources(string $role): void
    {
        $unrelated = Proposal::factory()->create();
        if ($role === User::ROLE_ADMIN) {
            User::factory()->create(['role' => User::ROLE_ADMIN]);
        }
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
        $this->assertModelExists($skill);
        $this->assertModelExists($unrelated);
        $this->assertModelExists($unrelated->freelancer);
        $this->assertModelExists($unrelated->project);
    }

    public static function statuses(): array
    {
        return array_map(fn (ProposalStatus $status) => [$status], ProposalStatus::cases());
    }

    public static function changedRoles(): array
    {
        return ['client' => [User::ROLE_CLIENT], 'admin' => [User::ROLE_ADMIN]];
    }

    public static function roles(): array
    {
        return ['client' => [User::ROLE_CLIENT], 'freelancer' => [User::ROLE_FREELANCER], 'admin' => [User::ROLE_ADMIN]];
    }
}
