<?php

namespace Tests\Feature\Settings;

use App\Models\DeliverableReview;
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

class DeliverableReviewAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('avatars');
        Storage::fake('deliverables');
    }

    #[DataProvider('roles')]
    public function test_review_history_guard_preserves_account_profile_avatar_and_submitted_work_for_all_roles(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        // A legacy reviewer with no ownership/upload history isolates the new reviewer foreign key guard.
        $review = DeliverableReview::factory()->create(['reviewed_by' => $user->id]);
        $version = $review->version;
        Storage::disk('deliverables')->put($version->storage_path, 'Private submitted history.');
        $this->assertFalse($user->projects()->exists());
        $this->assertFalse($user->submittedProposals()->exists());
        $this->assertFalse($user->createdMilestones()->exists());
        $this->assertFalse($user->createdTasks()->exists());
        $this->assertFalse($user->createdDeliverables()->exists());
        $this->assertFalse($user->uploadedDeliverableVersions()->exists());
        $this->assertTrue($user->completedDeliverableReviews()->exists());
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('photo.png'));
        $profile = FreelancerProfile::factory()->create(['user_id' => $user->id, 'is_public' => false]);
        $skill = Skill::factory()->create();
        $profile->skills()->attach($skill);
        $userBefore = $user->fresh()->getAttributes();
        $profileBefore = $profile->fresh()->getAttributes();
        $reviewBefore = $review->fresh()->getAttributes();
        $versionBefore = $version->fresh()->getAttributes();
        $photoBefore = Storage::disk('avatars')->get($user->avatar_path);

        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')
            ->assertHasErrors(['password'])
            ->assertSee('Your account cannot be deleted because it has deliverable review history.')
            ->assertNoRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($userBefore, $user->fresh()->getAttributes());
        $this->assertSame($profileBefore, $profile->fresh()->getAttributes());
        $this->assertTrue($profile->fresh()->skills->sole()->is($skill));
        $this->assertSame($reviewBefore, $review->fresh()->getAttributes());
        $this->assertSame($versionBefore, $version->fresh()->getAttributes());
        $this->assertSame($photoBefore, Storage::disk('avatars')->get($user->avatar_path));
        $this->assertSame('Private submitted history.', Storage::disk('deliverables')->get($version->storage_path));
    }

    #[DataProvider('roles')]
    public function test_no_history_account_cleanup_still_works_and_preserves_unrelated_reviews(string $role): void
    {
        $unrelated = DeliverableReview::factory()->create()->fresh();
        $before = $unrelated->getAttributes();
        $version = $unrelated->version;
        Storage::disk('deliverables')->put($version->storage_path, 'Unrelated submitted history.');
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
        $this->assertSame($before, $unrelated->fresh()->getAttributes());
        $this->assertSame('Unrelated submitted history.', Storage::disk('deliverables')->get($version->storage_path));
        $this->assertModelExists($skill);
    }

    public static function roles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }
}
