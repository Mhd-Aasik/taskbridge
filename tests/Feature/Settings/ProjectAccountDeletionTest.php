<?php

namespace Tests\Feature\Settings;

use App\Enums\ProjectStatus;
use App\Models\FreelancerProfile;
use App\Models\Project;
use App\Models\Skill;
use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectAccountDeletionTest extends TestCase
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
    public function test_project_history_blocks_account_deletion_and_preserves_all_account_resources(ProjectStatus $status): void
    {
        $project = Project::factory()->create(['status' => $status]);
        $user = $project->client;
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('photo.png'));
        $profile = FreelancerProfile::factory()->create(['user_id' => $user->id, 'is_public' => true]);
        $skill = Skill::factory()->create();
        $profile->skills()->attach($skill);
        $before = $user->fresh()->getAttributes();
        $profileBefore = $profile->fresh()->getAttributes();
        $photoBefore = Storage::disk('avatars')->get($user->avatar_path);

        Volt::test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasErrors(['password'])
            ->assertSee('Your account cannot be deleted because it has projects. Project history must be preserved.')
            ->assertNoRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame($profileBefore, $profile->fresh()->getAttributes());
        $this->assertTrue($profile->fresh()->skills->sole()->is($skill));
        $this->assertSame($photoBefore, Storage::disk('avatars')->get($user->avatar_path));
        $this->assertModelExists($project);
    }

    public function test_the_guard_still_protects_history_if_an_owners_role_has_changed(): void
    {
        $project = Project::factory()->create();
        $user = $project->client;
        $user->role = User::ROLE_FREELANCER;
        $user->save();
        $this->actingAs($user);

        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password']);

        $this->assertAuthenticatedAs($user);
        $this->assertModelExists($project);
    }

    #[DataProvider('roles')]
    public function test_accounts_without_projects_can_still_be_deleted_with_resource_cleanup(string $role): void
    {
        $otherProject = Project::factory()->create();
        if ($role === User::ROLE_ADMIN) {
            User::factory()->create(['role' => User::ROLE_ADMIN]);
        }
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('photo.png'));
        $profile = FreelancerProfile::factory()->create(['user_id' => $user->id]);

        Volt::test('settings.delete-user-form')
            ->set('password', 'password')
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertModelMissing($user);
        $this->assertModelMissing($profile);
        Storage::disk('avatars')->assertMissing($user->avatar_path);
        $this->assertModelExists($otherProject);
        $this->assertModelExists($otherProject->client);
    }

    public function test_guests_cannot_invoke_account_deletion(): void
    {
        $user = User::factory()->create();

        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertForbidden();

        $this->assertModelExists($user);
    }

    public static function statuses(): array
    {
        return array_map(fn (ProjectStatus $status) => [$status], ProjectStatus::cases());
    }

    public static function roles(): array
    {
        return ['client' => [User::ROLE_CLIENT], 'freelancer' => [User::ROLE_FREELANCER], 'admin' => [User::ROLE_ADMIN]];
    }
}
