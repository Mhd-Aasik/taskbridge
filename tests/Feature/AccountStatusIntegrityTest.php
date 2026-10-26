<?php

namespace Tests\Feature;

use App\Models\PendingRegistration;
use App\Models\User;
use App\Notifications\CompleteRegistration;
use App\Services\AccountPhotos;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AccountStatusIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_and_profile_extra_input_cannot_set_system_status_or_roles(): void
    {
        Notification::fake();
        $forged = ['suspended_at' => '2030-01-01', 'suspended_by' => 999999, 'suspension_reason' => 'Forged internal data', 'access_version' => 999, 'role' => 'admin'];
        Volt::test('auth.register')->set('name', 'Fresh client')->set('email', 'safe@example.test')->set('role', 'client')->call('register', $forged)->assertHasNoErrors();
        $this->assertDatabaseCount('users', 0);
        $notification = Notification::sent(new AnonymousNotifiable, CompleteRegistration::class)->sole();
        parse_str(parse_url($notification->setupUrl, PHP_URL_FRAGMENT), $parameters);
        $pending = PendingRegistration::sole();
        $this->post(route('registration.complete', $pending->id), ['email' => $pending->email, 'setup_token' => $parameters['token'], 'password' => 'VerifiedPassword123!', 'password_confirmation' => 'VerifiedPassword123!', 'suspended_at' => $forged['suspended_at'], 'access_version' => 999])->assertRedirectToRoute('login');
        $user = User::where('email', 'safe@example.test')->sole();
        $this->assertTrue($user->isActive());
        $this->assertSame(0, $user->access_version);
        $this->assertSame('client', $user->role);
        $this->assertNull($user->suspended_by);
        $this->assertNull($user->suspension_reason);
        $this->actingAs($user);
        Volt::test('settings.profile')->set('name', 'Updated client')->set('email', $user->email)->call('updateProfileInformation', $forged)->assertHasNoErrors();
        $this->assertSame('Updated client', $user->fresh()->name);
        $this->assertTrue($user->fresh()->isActive());
        $this->assertSame(0, $user->fresh()->access_version);
        $this->assertSame('client', $user->fresh()->role);
    }

    public function test_suspension_actor_foreign_key_restricts_deletion_with_a_clear_cleanup_guard(): void
    {
        Storage::fake('avatars');
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['suspended_at' => now(), 'suspended_by' => $admin->id]);
        $this->actingAs($admin);
        $admin = app(AccountPhotos::class)->replace($admin, UploadedFile::fake()->image('admin.png'));
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password'])->assertSeeText('administrative account-status history');
        Storage::disk('avatars')->assertExists($admin->avatar_path);
        try {
            DB::table('users')->where('id', $admin->id)->delete();
            $this->fail('Referenced suspension actors cannot be erased.');
        } catch (QueryException) {
            $this->assertModelExists($target);
        }
        $this->expectException(QueryException::class);
        User::factory()->create(['suspended_by' => 999999]);
    }
}
