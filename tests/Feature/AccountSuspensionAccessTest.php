<?php

namespace Tests\Feature;

use App\Actions\Notifications\ManageNotifications;
use App\Actions\Projects\PublishProject;
use App\Models\FreelancerProfile;
use App\Models\Project;
use App\Models\User;
use App\Services\AccountPhotos;
use App\Support\AccountAccess;
use App\Support\ProjectMessaging;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Livewire\Volt\Volt;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\ManagesAccounts;
use Tests\TestCase;

class AccountSuspensionAccessTest extends TestCase
{
    use ManagesAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('avatars');
        Storage::fake('deliverables');
        Storage::fake('project_covers');
    }

    public function test_verified_suspended_login_gets_a_safe_explanation_but_invalid_credentials_are_generic(): void
    {
        $admin = $this->accountAdmin();
        $user = User::factory()->create(['role' => 'client']);
        $this->changeAccount($user, true, ['reason' => 'INTERNAL-REASON-NEVER-REVEAL']);
        Auth::logout();
        session()->flush();
        foreach ([[$user->email, 'wrong'], ['nonexistent@example.test', 'wrong']] as [$email, $password]) {
            Volt::test('auth.login')->set('email', $email)->set('password', $password)->call('login')->assertHasErrors(['email'])->assertSeeText(__('auth.failed'))->assertDontSee(AccountAccess::UNAVAILABLE)->assertDontSee('INTERNAL-REASON-NEVER-REVEAL');
            $this->assertGuest();
        }
        Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->set('remember', true)->call('login')->assertHasErrors(['email'])->assertSeeText(AccountAccess::UNAVAILABLE)->assertDontSee('INTERNAL-REASON-NEVER-REVEAL');
        $this->assertGuest();
    }

    public function test_tokens_and_targeted_database_sessions_are_revoked_without_affecting_other_accounts(): void
    {
        $admin = $this->accountAdmin();
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $other = User::factory()->create();
        config(['session.driver' => 'database']);
        foreach ([['target-one', $user->id], ['target-two', $user->id], ['other-session', $other->id]] as [$id, $userId]) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => base64_encode(serialize([])), 'last_activity' => time()]);
        }
        $this->changeAccount($user, true);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session', 'user_id' => $other->id]);
        $this->assertNull(Auth::getProvider()->retrieveByToken($user->id, 'old-remember-token'));
        $this->assertNull(Auth::getProvider()->retrieveByToken($user->id, $user->fresh()->remember_token));
        $suspensionToken = $user->fresh()->remember_token;
        $this->changeAccount($user, false);
        $this->assertNull(Auth::getProvider()->retrieveByToken($user->id, $suspensionToken));
        $this->assertNull(Auth::getProvider()->retrieveByToken($user->id, 'old-remember-token'));
    }

    public function test_existing_session_is_denied_and_reactivation_does_not_resurrect_it(): void
    {
        $admin = $this->accountAdmin();
        $user = User::factory()->create(['role' => 'client']);
        $oldStamp = ['id' => $user->id, 'version' => 0];
        $this->actingAs($user)->withSession([AccountAccess::SESSION_KEY => $oldStamp])->get(route('dashboard'))->assertRedirect(route('client.dashboard'));
        $this->actingAs($admin);
        $this->changeAccount($user, true);
        $this->actingAs($user)->withSession([AccountAccess::SESSION_KEY => $oldStamp])->get(route('settings.profile'))->assertRedirect(route('login'))->assertSessionHasErrors(['email']);
        $this->assertGuest();
        $this->actingAs($admin);
        $this->changeAccount($user, false);
        $this->actingAs($user->fresh())->withSession([AccountAccess::SESSION_KEY => $oldStamp])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        session()->flush();
        Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->call('login')->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
        $this->assertSame(['id' => $user->id, 'version' => 2], session(AccountAccess::SESSION_KEY));
        $this->get(route('client.dashboard'))->assertOk();
    }

    public function test_real_recaller_cookie_is_rejected_during_suspension_and_after_reactivation(): void
    {
        $admin = $this->accountAdmin();
        $user = User::factory()->create(['remember_token' => 'known-cookie-token']);
        $cookieName = Auth::guard('web')->getRecallerName();
        $cookie = $user->id.'|known-cookie-token|'.$user->password;
        $this->changeAccount($user, true);
        Auth::forgetGuards();
        $this->withCookie($cookieName, $cookie)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->actingAs($admin);
        $this->changeAccount($user, false);
        Auth::forgetGuards();
        session()->flush();
        $this->withCookie($cookieName, $cookie)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_suspended_livewire_update_polling_upload_and_private_endpoints_are_denied(): void
    {
        $admin = $this->accountAdmin();
        $user = User::factory()->create(['role' => 'client']);
        $this->actingAs($user);
        $user = app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('avatar.png'));
        $page = Volt::test('settings.profile')->set('name', 'Must not save');
        $this->actingAs($admin);
        $this->changeAccount($user, true);
        $this->actingAs($user);
        $page->call('updateProfileInformation')->assertForbidden();
        $this->assertNotSame('Must not save', $user->fresh()->name);
        $this->actingAs($user)->getJson(route('account.photo', $user))->assertForbidden();
        $this->actingAs($user)->postJson(EndpointResolver::updatePath(), ['components' => []], ['X-Livewire' => 'true'])->assertForbidden();
        $upload = URL::temporarySignedRoute('livewire.upload-file', now()->addMinutes(5), absolute: false);
        $this->actingAs($user)->postJson($upload, ['files' => [UploadedFile::fake()->image('new.png')]])->assertForbidden();
        $preview = URL::temporarySignedRoute('livewire.preview-file', now()->addMinutes(5), ['filename' => 'fake.png'], absolute: false);
        $this->actingAs($user)->getJson($preview)->assertForbidden();
        Storage::disk('avatars')->assertExists($user->avatar_path);
        $this->actingAs($user);
        try {
            app(ManageNotifications::class)->captureUnread();
            $this->fail('Suspended polling/actions are denied.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        try {
            app(ManageNotifications::class)->markCapturedRead('forged');
            $this->fail('Suspended notification mutation is denied before token validation.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_direct_actions_and_shared_contexts_refuse_stale_user_instances_even_after_reactivation(): void
    {
        $admin = $this->accountAdmin();
        $user = User::factory()->create(['role' => 'client']);
        $project = Project::factory()->create(['client_id' => $user->id]);
        $this->changeAccount($user, true);
        $this->changeAccount($user, false);
        $before = $project->fresh()->getAttributes();
        $this->actingAs($user);
        foreach ([fn () => app(PublishProject::class)->publish($user, $project->id, true), fn () => ProjectMessaging::account(), fn () => app(AccountPhotos::class)->replace($user, UploadedFile::fake()->image('denied.png')), fn () => app(ManageNotifications::class)->captureUnread()] as $operation) {
            try {
                $operation();
                $this->fail('Old loaded users cannot resume after revocation.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame($before, $project->fresh()->getAttributes());
        $this->assertSame([], Storage::disk('avatars')->allFiles());
    }

    public function test_password_recovery_does_not_reactivate_or_expose_the_internal_reason(): void
    {
        $admin = $this->accountAdmin();
        $user = User::factory()->create();
        $this->changeAccount($user, true, ['reason' => 'INTERNAL-RECOVERY-REASON']);
        $fields = array_flip(['suspended_at', 'suspended_by', 'suspension_reason']);
        $version = $user->fresh()->access_version;
        $before = array_intersect_key($user->fresh()->getAttributes(), $fields);
        Auth::logout();
        session()->flush();
        $token = Password::createToken($user);
        Volt::test('auth.reset-password', ['token' => $token])->set('email', $user->email)->set('password', 'new-password')->set('password_confirmation', 'new-password')->call('resetPassword')->assertHasNoErrors()->assertRedirect(route('login', absolute: false))->assertDontSee('INTERNAL-RECOVERY-REASON');
        $this->assertSame($before, array_intersect_key($user->fresh()->getAttributes(), $fields));
        $this->assertSame($version + 1, $user->fresh()->access_version);
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertGuest();
    }

    public function test_last_active_admin_deletion_is_refused_before_private_cleanup(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['role' => 'admin', 'suspended_at' => now()]);
        $profile = FreelancerProfile::factory()->create(['user_id' => $admin->id]);
        $this->actingAs($admin);
        $admin = app(AccountPhotos::class)->replace($admin, UploadedFile::fake()->image('admin.png'));
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password'])->assertSeeText('last active administrator')->assertNoRedirect();
        $this->assertModelExists($admin);
        $this->assertModelExists($profile);
        Storage::disk('avatars')->assertExists($admin->avatar_path);
    }

    public function test_simulated_competing_admin_deletions_leave_one_active_administrator(): void
    {
        $first = User::factory()->create(['role' => 'admin']);
        $second = User::factory()->create(['role' => 'admin']);
        // Sequential committed selections simulate the competing attempts; not parallel MySQL.
        $this->actingAs($first);
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasNoErrors()->assertRedirect('/');
        $this->assertModelMissing($first);
        $this->actingAs($second);
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password']);
        $this->assertSame(1, User::where('role', 'admin')->whereNull('suspended_at')->count());
    }

    public function test_suspended_user_can_log_out_and_a_reactivated_account_with_status_history_cannot_be_deleted(): void
    {
        $admin = $this->accountAdmin();
        $target = User::factory()->create();
        $this->changeAccount($target, true);
        $this->actingAs($target)->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
        $this->actingAs($admin);
        $this->changeAccount($target, false);
        Auth::logout();
        session()->flush();
        Auth::login($target->fresh());
        Volt::test('settings.delete-user-form')->set('password', 'password')->call('deleteUser')->assertHasErrors(['password'])->assertSeeText('administrative account-status history');
        $this->assertModelExists($target);
    }
}
