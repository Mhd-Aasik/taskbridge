<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\ChangeAccountPassword;
use App\Models\EmailVerificationToken;
use App\Models\Project;
use App\Models\User;
use App\Notifications\VerifyExistingEmail;
use App\Services\AccountSessions;
use App\Support\AccountAccess;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Volt\Volt;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WorkflowRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
    }

    #[DataProvider('roles')]
    public function test_guest_verification_post_precedes_login_and_discards_another_roles_intended_destination(string $role): void
    {
        $user = User::factory()->unverified()->create(['role' => $role]);
        $this->withSession(['url.intended' => route($role === 'client' ? 'freelancer.projects.index' : 'client.projects.index')]);
        $this->post(route('verification.send'), ['email' => $user->email])->assertRedirectToRoute('verification.notice');
        $notifications = Notification::sent($user, VerifyExistingEmail::class);
        $this->assertCount(1, $notifications);
        $url = $notifications->first()->verificationLink;
        parse_str(parse_url($url, PHP_URL_FRAGMENT), $params);
        $record = EmailVerificationToken::sole();
        $this->get(explode('#', $url)[0])->assertOk();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->post(route('verification.complete', $record->id), ['email' => $user->email, 'token' => $params['token']])->assertRedirectToRoute('login');
        $this->assertGuest();
        Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->call('login')->assertHasNoErrors();
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->assertFalse(session()->has('url.intended'));
        $this->get(route('dashboard'))->assertRedirectToRoute($role.'.dashboard');
        $this->get(route($role.'.dashboard'))->assertOk();
    }

    public static function roles(): array
    {
        return [['client'], ['freelancer'], ['admin']];
    }

    public function test_already_verified_shortcuts_discard_external_intended_urls(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['url.intended' => 'https://outside.example/private']);
        Volt::test('auth.verify-email')->call('sendVerification')->assertRedirect(route('dashboard', absolute: false));
        $this->withSession(['url.intended' => route('freelancer.projects.index')]);
        Volt::test('settings.profile')->call('resendVerificationNotification')->assertRedirect(route('dashboard', absolute: false));
        Notification::assertNothingSent();
    }

    public function test_password_recovery_revokes_an_old_session_and_consumes_the_mail_token_without_changing_role(): void
    {
        $user = User::factory()->create(['remember_token' => 'original-remember-token']);
        $staleUser = $user->fresh();
        Volt::test('auth.forgot-password')->set('email', $user->email)->call('sendPasswordResetLink')->assertHasNoErrors();
        $notification = Notification::sent($user, ResetPassword::class)->sole();
        $mail = $notification->toMail($user);
        $this->get($mail->actionUrl)->assertOk();
        $form = Volt::test('auth.reset-password', ['token' => $notification->token])->set('email', $user->email)
            ->set('password', 'recovered-password')->set('password_confirmation', 'recovered-password');
        $form->call('resetPassword')->assertHasNoErrors()->assertRedirect(route('login', absolute: false));
        $saved = $user->fresh();
        $this->assertTrue(Hash::check('recovered-password', $saved->password));
        $this->assertSame(User::ROLE_CLIENT, $saved->role);
        $this->assertSame(1, $saved->access_version);
        $this->assertNotSame('original-remember-token', $saved->remember_token);
        $this->assertTrue($saved->isActive());
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $snapshot = $saved->getAttributes();
        $form->call('resetPassword')->assertHasErrors(['email']);
        $this->assertSame($snapshot, $user->fresh()->getAttributes());
        $this->actingAs($staleUser)->withSession([AccountAccess::SESSION_KEY => ['id' => $user->id, 'version' => 0]])
            ->get(route('dashboard'))->assertRedirectToRoute('login');
        Volt::test('auth.login')->set('email', $user->email)->set('password', 'recovered-password')->call('login')
            ->assertHasNoErrors()->assertRedirect(route('dashboard', absolute: false));
        $this->get(route('dashboard'))->assertRedirectToRoute('client.dashboard');
    }

    public function test_a_stale_password_settings_form_cannot_overwrite_a_password_changed_after_it_was_opened(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Volt::test('settings.password')->set('current_password', 'password')->set('password', 'stale-new-password')->set('password_confirmation', 'stale-new-password');
        User::whereKey($user->id)->update(['password' => Hash::make('changed-after-opening')]);
        $form->call('updatePassword')->assertHasErrors(['current_password']);
        $this->assertTrue(Hash::check('changed-after-opening', $user->fresh()->password));
    }

    public function test_password_change_rotates_remember_tokens_and_keeps_only_the_updated_current_session_authorized(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $old = $user->fresh();
        $this->actingAs($user);
        Volt::test('settings.password')->set('current_password', 'password')->set('password', 'updated-password')
            ->set('password_confirmation', 'updated-password')->call('updatePassword')->assertHasNoErrors();
        $this->assertSame(1, $user->fresh()->access_version);
        $this->assertNotSame('old-remember-token', $user->fresh()->remember_token);
        $this->get(route('settings.profile'))->assertOk();
        $this->assertSame(['id' => $user->id, 'version' => 1], session(AccountAccess::SESSION_KEY));
        Auth::setUser($old);
        $this->withSession([AccountAccess::SESSION_KEY => ['id' => $old->id, 'version' => 0]])->get(route('settings.profile'))->assertRedirectToRoute('login');
    }

    public function test_password_change_refreshes_the_current_request_authorization_snapshot(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $form = Volt::test('settings.password')->set('current_password', 'password')->set('password', 'updated-password')->set('password_confirmation', 'updated-password');
        // Model the actor snapshot supplied by the web middleware on the same update request.
        $request = request();
        $request->attributes->set('taskbridge.available_actor', $user->fresh());
        $form->instance()->updatePassword();
        $this->assertSame(1, $request->attributes->get('taskbridge.available_actor')->access_version);
        $this->assertTrue(Gate::forUser(Auth::user())->allows('create', Project::class));
    }

    #[DataProvider('passwordOperations')]
    public function test_password_changes_delete_only_the_target_accounts_database_sessions(string $operation): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user);
        // Keep the test request store isolated; exercise the real database session-revocation service.
        session()->start();
        config(['session.driver' => 'database', 'session.connection' => 'sqlite']);
        foreach (['target-one' => $user->id, 'target-two' => $user->id, 'other-session' => $other->id, 'guest-session' => null] as $id => $owner) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner, 'payload' => base64_encode('isolated test session'), 'last_activity' => time()]);
        }
        if ($operation === 'settings') {
            app(ChangeAccountPassword::class)->update('password', 'updated-password');
        } else {
            $token = Password::createToken($user);
            app(ChangeAccountPassword::class)->reset(['email' => $user->email, 'password' => 'updated-password', 'password_confirmation' => 'updated-password', 'token' => $token]);
        }
        $this->assertEqualsCanonicalizing(['other-session', 'guest-session'], DB::table('sessions')->pluck('id')->all());
        $this->assertSame(1, $user->fresh()->access_version);
        $this->assertSame(0, $other->fresh()->access_version);
        $this->assertTrue(Hash::check('password', $other->fresh()->password));
    }

    public static function passwordOperations(): array
    {
        return [['settings'], ['reset']];
    }

    public function test_failed_session_revocation_rolls_back_recovery_and_retains_the_reset_token(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $before = $user->fresh()->getAttributes();
        $this->mock(AccountSessions::class)->shouldReceive('revoke')->once()->andThrow(new RuntimeException('Simulated session storage failure'));
        try {
            Volt::test('auth.reset-password', ['token' => $token])->set('email', $user->email)->set('password', 'updated-password')->set('password_confirmation', 'updated-password')->call('resetPassword');
            $this->fail('A failed password action must not report success.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated session storage failure', $exception->getMessage());
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertTrue(Password::tokenExists($user, $token));
    }

    public function test_expired_mail_token_cannot_change_a_password_or_revoke_access(): void
    {
        $user = User::factory()->create();
        Volt::test('auth.forgot-password')->set('email', $user->email)->call('sendPasswordResetLink')->assertHasNoErrors();
        $token = Notification::sent($user, ResetPassword::class)->sole()->token;
        $before = $user->fresh()->getAttributes();
        $this->travel(61)->minutes();
        Volt::test('auth.reset-password', ['token' => $token])->set('email', $user->email)->set('password', 'updated-password')->set('password_confirmation', 'updated-password')
            ->call('resetPassword')->assertHasErrors(['email']);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertGuest();
    }
}
