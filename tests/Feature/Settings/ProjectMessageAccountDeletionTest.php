<?php

namespace Tests\Feature\Settings;

use App\Models\FreelancerProfile;
use App\Models\ProjectMessage;
use App\Models\ProjectReadCursor;
use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectMessageAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('histories')]
    public function test_legacy_message_or_cursor_actor_history_blocks_deletion_before_cleanup(string $kind, string $role): void
    {
        Storage::fake('avatars');
        $actor = User::factory()->create(['role' => $role]);
        $record = $kind === 'sender' ? ProjectMessage::factory()->create(['sender_id' => $actor->id]) : ProjectReadCursor::factory()->create(['user_id' => $actor->id]);
        $this->actingAs($actor);
        $actor = app(AccountPhotos::class)->replace($actor, UploadedFile::fake()->image('photo.png'));
        $profile = FreelancerProfile::factory()->create(['user_id' => $actor->id, 'is_public' => false]);
        $before = [$actor->fresh()->getAttributes(), $profile->fresh()->getAttributes(), $record->fresh()->getAttributes(), Storage::disk('avatars')->get($actor->avatar_path)];
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password'])->assertSeeText('Your account cannot be deleted because it has project conversation history.')->assertNoRedirect();
        $this->assertAuthenticatedAs($actor);
        $this->assertSame($before, [$actor->fresh()->getAttributes(), $profile->fresh()->getAttributes(), $record->fresh()->getAttributes(), Storage::disk('avatars')->get($actor->avatar_path)]);
    }

    #[DataProvider('roles')]
    public function test_accounts_without_history_still_delete_and_clean_up_own_photo_and_profile(string $role): void
    {
        Storage::fake('avatars');
        $record = ProjectReadCursor::factory()->create()->fresh();
        if ($role === User::ROLE_ADMIN) {
            User::factory()->create(['role' => User::ROLE_ADMIN]);
        }
        $before = $record->getAttributes();
        $actor = User::factory()->create(['role' => $role]);
        $this->actingAs($actor);
        $actor = app(AccountPhotos::class)->replace($actor, UploadedFile::fake()->image('photo.png'));
        $profile = FreelancerProfile::factory()->create(['user_id' => $actor->id]);
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');
        $this->assertGuest();
        $this->assertModelMissing($actor);
        $this->assertModelMissing($profile);
        Storage::disk('avatars')->assertMissing($actor->avatar_path);
        $this->assertSame($before, $record->fresh()->getAttributes());
    }

    public static function roles(): array
    {
        return [[User::ROLE_CLIENT], [User::ROLE_FREELANCER], [User::ROLE_ADMIN]];
    }

    public static function histories(): array
    {
        $cases = [];
        foreach (['sender', 'reader'] as $kind) {
            foreach (self::roles() as [$role]) {
                $cases[] = [$kind, $role];
            }
        }

        return $cases;
    }
}
