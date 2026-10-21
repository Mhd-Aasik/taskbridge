<?php

namespace Tests\Feature\Settings;

use App\Models\FreelancerProfile;
use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsProjectFeedback;
use Tests\TestCase;

class ProjectRatingAccountDeletionTest extends TestCase
{
    use BuildsProjectFeedback, RefreshDatabase;

    #[DataProvider('participants')]
    public function test_authored_or_received_feedback_refuses_deletion_before_avatar_or_profile_cleanup(bool $reviewer): void
    {
        Storage::fake('avatars');
        $assignment = $this->completedAssignment();
        $rating = $this->feedbackFrom($assignment);
        $user = $reviewer ? $rating->reviewer : $rating->reviewee;
        $profile = FreelancerProfile::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('photo.png'));
        $before = $rating->getAttributes();
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password'])->assertSeeText('project feedback history')->assertNoRedirect();
        $this->assertModelExists($user);
        $this->assertModelExists($profile);
        $this->assertSame($before, $rating->fresh()->getAttributes());
        Storage::disk('avatars')->assertExists($user->avatar_path);
    }

    public function test_an_unrelated_account_with_no_history_can_still_be_deleted(): void
    {
        Storage::fake('avatars');
        $rating = $this->feedbackFrom($this->completedAssignment());
        $user = User::factory()->create(['role' => User::ROLE_FREELANCER]);
        $profile = FreelancerProfile::factory()->create(['user_id' => $user->id]);
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('photo.png'));
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');
        $this->assertModelMissing($user);
        $this->assertModelMissing($profile);
        Storage::disk('avatars')->assertMissing($user->avatar_path);
        $this->assertModelExists($rating);
    }

    public static function participants(): array
    {
        return [[true], [false]];
    }
}
