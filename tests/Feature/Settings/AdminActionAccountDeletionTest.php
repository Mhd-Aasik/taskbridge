<?php

namespace Tests\Feature\Settings;

use App\Models\AdminActionLog;
use App\Models\FreelancerProfile;
use App\Models\User;
use App\Services\AccountPhotos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AdminActionAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_audited_admin_deletion_is_refused_before_profile_or_photo_cleanup(): void
    {
        Storage::fake('avatars');
        $log = AdminActionLog::factory()->create()->fresh();
        $admin = $log->actor;
        $profile = FreelancerProfile::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin);
        $admin = app(AccountPhotos::class)->replace($admin, UploadedFile::fake()->image('photo.png'));
        $before = $log->getAttributes();
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password'])->assertSeeText('administrative action history')->assertNoRedirect();
        $this->assertModelExists($admin);
        $this->assertModelExists($profile);
        $this->assertSame($before, $log->fresh()->getAttributes());
        Storage::disk('avatars')->assertExists($admin->avatar_path);
    }

    public function test_admin_without_audit_or_domain_history_can_still_delete_their_account(): void
    {
        Storage::fake('avatars');
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin);
        $admin = app(AccountPhotos::class)->replace($admin, UploadedFile::fake()->image('photo.png'));
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');
        $this->assertModelMissing($admin);
        Storage::disk('avatars')->assertMissing($admin->avatar_path);
    }
}
