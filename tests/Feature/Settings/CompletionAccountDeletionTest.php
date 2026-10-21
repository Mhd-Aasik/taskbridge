<?php

namespace Tests\Feature\Settings;

use App\Models\FreelancerProfile;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompletionAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('completionHistories')]
    public function test_legacy_completion_actor_history_refuses_deletion_before_any_cleanup(string $class, string $role): void
    {
        Storage::fake('avatars');
        $actor = User::factory()->create(['role' => $role]);
        $record = $class::factory()->create(['completed_by' => $actor->id, 'completed_at' => now(), 'completion_note' => 'Preserve outcome.'])->fresh();
        $this->actingAs($actor);
        $actor = app(AccountPhotos::class)->replace($actor, UploadedFile::fake()->image('photo.png'));
        $profile = FreelancerProfile::factory()->create(['user_id' => $actor->id, 'is_public' => false]);
        $before = $record->getAttributes();
        $photo = Storage::disk('avatars')->get($actor->avatar_path);
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password'])
            ->assertSeeText('Your account cannot be deleted because it has completion history.')->assertNoRedirect();
        $this->assertAuthenticatedAs($actor);
        $this->assertModelExists($actor);
        $this->assertModelExists($profile);
        $this->assertSame($photo, Storage::disk('avatars')->get($actor->avatar_path));
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertSame($role, $actor->fresh()->role);
        $this->assertFalse($profile->fresh()->is_public);
    }

    public static function completionHistories(): array
    {
        $cases = [];
        foreach ([Project::class, Milestone::class] as $class) {
            foreach ([User::ROLE_CLIENT, User::ROLE_FREELANCER, User::ROLE_ADMIN] as $role) {
                $cases[] = [$class, $role];
            }
        }

        return $cases;
    }
}
