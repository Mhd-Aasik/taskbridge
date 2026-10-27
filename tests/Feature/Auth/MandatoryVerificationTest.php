<?php

namespace Tests\Feature\Auth;

use App\Actions\Notifications\ManageNotifications;
use App\Models\User;
use App\Services\AccountPhotos;
use App\Support\AccountAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Livewire\Volt\Volt;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MandatoryVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_unverified_accounts_keep_passwords_but_all_roles_receive_only_verification_access(): void
    {
        Notification::fake();
        foreach (['client', 'freelancer', 'admin'] as $role) {
            $user = User::factory()->unverified()->create(['role' => $role]);
            $before = $user->fresh()->getAttributes();
            Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->call('login')->assertHasErrors('email');
            $this->assertGuest();
            $this->get('/dashboard')->assertRedirectToRoute('login');
            $this->get('/'.$role.'/dashboard')->assertRedirectToRoute('login');
            $this->get('/settings/profile')->assertRedirectToRoute('login');
            $this->get('/verify-email')->assertOk();
            $this->post(route('verification.send'), ['email' => $user->email])->assertRedirectToRoute('verification.notice');
            $this->assertSame($before, $user->fresh()->getAttributes());
            $this->post('/logout')->assertRedirect('/');
        }
    }

    public function test_unverified_livewire_upload_preview_private_download_and_action_entry_points_are_denied(): void
    {
        Storage::fake('avatars');
        $user = User::factory()->unverified()->create(['role' => 'client']);
        $this->actingAs($user);
        $this->postJson(EndpointResolver::updatePath(), ['components' => []], ['X-Livewire' => 'true'])->assertForbidden();
        $upload = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), absolute: false);
        $this->actingAs($user)->postJson($upload, ['files' => [UploadedFile::fake()->image('denied.png')]])->assertForbidden();
        $preview = URL::temporarySignedRoute('livewire.preview-file', now()->addMinutes(5), ['filename' => 'fake.png'], absolute: false);
        $this->actingAs($user)->getJson($preview)->assertForbidden();
        $this->actingAs($user)->getJson(route('account.photo', $user))->assertForbidden();
        $this->actingAs($user)->getJson('/projects/1/deliverables/1/versions/1/download')->assertForbidden();
        $this->assertGuest();
        foreach ([fn () => app(ManageNotifications::class)->captureUnread(), fn () => app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('denied.png'))] as $action) {
            try {
                $action();
                $this->fail('Unverified action must be denied.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertFalse(AccountAccess::allows($user, fresh: true));
        $this->assertSame([], Storage::disk('avatars')->allFiles());
    }

    public function test_stale_verified_snapshot_cannot_bypass_unverification(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $this->actingAs($user);
        $component = Volt::test('settings.profile')->set('name', 'Must not change');
        DB::table('users')->where('id', $user->id)->update(['email_verified_at' => null]);
        $this->assertFalse(AccountAccess::allows($user, fresh: true));
        $component->call('updateProfileInformation')->assertForbidden();
        $this->assertNotSame('Must not change', $user->fresh()->name);
    }

    public function test_email_change_revokes_old_sessions_and_requires_verification_again_without_changing_password(): void
    {
        $user = User::factory()->create(['role' => 'client']);
        $beforePassword = $user->password;
        $oldVersion = $user->access_version;
        $this->actingAs($user);
        Volt::test('settings.profile')->set('name', $user->name)->set('email', 'new-address@taskbridge.test')->call('updateProfileInformation')->assertHasNoErrors()->assertRedirect('/verify-email');
        $saved = $user->fresh();
        $this->assertNull($saved->email_verified_at);
        $this->assertSame($beforePassword, $saved->password);
        $this->assertSame($oldVersion + 1, $saved->access_version);
        $this->get('/dashboard')->assertRedirectToRoute('login');
        $this->actingAs($saved)->withSession([AccountAccess::SESSION_KEY => ['id' => $saved->id, 'version' => $oldVersion]])->get('/verify-email')->assertRedirectToRoute('login');
    }

    public function test_suspended_accounts_cannot_use_verification_as_an_access_bypass(): void
    {
        $user = User::factory()->unverified()->create(['suspended_at' => now()]);
        $this->actingAs($user)->get('/verify-email')->assertRedirectToRoute('login');
        Notification::fake();
        $this->post('/verify-email', ['email' => $user->email])->assertRedirectToRoute('verification.notice');
        Notification::assertNothingSent();
    }

    public function test_production_staging_switch_preserves_existing_access_until_explicit_enforcement(): void
    {
        config(['auth.require_verified_email' => false]);
        $admin = User::factory()->unverified()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/dashboard')->assertRedirectToRoute('admin.dashboard');
        $this->get('/admin/dashboard')->assertOk();
        config(['auth.require_verified_email' => true]);
        $this->get('/admin/dashboard')->assertRedirectToRoute('verification.notice');
        $admin->markEmailAsVerified();
        $this->actingAs($admin->fresh())->get('/admin/dashboard')->assertOk();
    }
}
