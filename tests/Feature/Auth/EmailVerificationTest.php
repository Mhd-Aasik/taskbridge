<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\RegisterByEmail;
use App\Actions\Accounts\VerifyExistingEmail;
use App\Models\EmailVerificationToken;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Notifications\CompleteRegistration;
use App\Notifications\VerifyExistingEmail as VerificationNotification;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\EnforcedCsrfToken;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function issue(User $user): array
    {
        Notification::fake();
        app(VerifyExistingEmail::class)->request($user->email, '192.0.2.8');
        $notification = Notification::sent($user, VerificationNotification::class)->last();
        parse_str(parse_url($notification->verificationLink, PHP_URL_FRAGMENT), $params);

        return [EmailVerificationToken::where('user_id', $user->id)->sole(), $params['token']];
    }

    private function complete(EmailVerificationToken $record, string $token, array $changes = [])
    {
        return $this->post(route('verification.complete', $record->id), array_replace(['email' => $record->email, 'token' => $token], $changes));
    }

    public function test_existing_verification_needs_no_login_get_never_verifies_and_post_preserves_password_role_and_status(): void
    {
        foreach (['admin', 'client', 'freelancer'] as $role) {
            $user = User::factory()->unverified()->create(['role' => $role]);
            $before = $user->fresh()->getAttributes();
            Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->call('login')->assertHasErrors('email');
            $this->assertGuest();
            [$record, $token] = $this->issue($user);
            $this->assertSame(hash('sha256', $token), $record->token_hash);
            $this->get(route('verification.confirm', $record->id))->assertOk()->assertDontSee($token);
            $this->get(route('verification.confirm', $record->id), ['Purpose' => 'prefetch'])->assertOk();
            $this->assertSame($before, $user->fresh()->getAttributes());
            $this->assertNull($record->fresh()->consumed_at);
            $this->complete($record, $token)->assertRedirectToRoute('login');
            $this->assertGuest();
            $saved = $user->fresh();
            $this->assertNotNull($saved->email_verified_at);
            foreach (['id', 'password', 'role', 'email', 'access_version', 'suspended_at', 'remember_token'] as $key) {
                $this->assertSame($before[$key], $saved->getAttributes()[$key]);
            }
            $this->assertNotNull($record->fresh()->consumed_at);
            $this->complete($record, $token)->assertStatus(422);
            Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->call('login')->assertHasNoErrors()->assertRedirect('/dashboard');
            $this->get('/dashboard')->assertRedirectToRoute($role.'.dashboard');
            $this->post('/logout');
        }
    }

    public function test_expired_tampered_wrong_email_and_role_or_password_injection_cannot_verify(): void
    {
        $user = User::factory()->unverified()->create();
        [$record, $token] = $this->issue($user);
        foreach ([['token' => str_repeat('a', 64)], ['email' => 'other@taskbridge.test'], ['role' => 'admin'], ['password' => 'Injected123!'], ['user_id' => 99], ['token' => ['array']]] as $changes) {
            $this->complete($record, $token, $changes)->assertStatus(422);
            $this->assertNull($user->fresh()->email_verified_at);
            $this->assertNull($record->fresh()->consumed_at);
        }
        $this->travel(61)->minutes();
        $this->complete($record, $token)->assertStatus(422);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_resend_rotates_token_and_failed_delivery_preserves_previous_token_without_disclosing_accounts(): void
    {
        $user = User::factory()->unverified()->create();
        [$record, $old] = $this->issue($user);
        $this->travel(61)->seconds();
        app(VerifyExistingEmail::class)->request($user->email, '192.0.2.8');
        $notification = Notification::sent($user, VerificationNotification::class)->last();
        parse_str(parse_url($notification->verificationLink, PHP_URL_FRAGMENT), $params);
        $this->complete($record, $old)->assertStatus(422);
        $before = $record->fresh()->getAttributes();
        $this->travel(61)->seconds();
        $this->mock(Dispatcher::class)->shouldReceive('send')->andThrow(new TransportException('PRIVATE-TRANSPORT-DETAIL'));
        $this->post(route('verification.send'), ['email' => $user->email])->assertRedirectToRoute('verification.notice')->assertSessionHas('status', VerifyExistingEmail::STATUS);
        $this->post(route('verification.send'), ['email' => 'unknown@taskbridge.test'])->assertRedirectToRoute('verification.notice')->assertSessionHas('status', VerifyExistingEmail::STATUS);
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->complete($record, $params['token'])->assertRedirectToRoute('login');
    }

    public function test_suspension_email_change_or_password_recovery_revocation_invalidates_issued_token(): void
    {
        foreach (['suspended_at' => now(), 'email' => 'changed@taskbridge.test', 'access_version' => 9] as $field => $value) {
            $user = User::factory()->unverified()->create();
            [$record, $token] = $this->issue($user);
            DB::table('users')->where('id', $user->id)->update([$field => $value]);
            $this->complete($record, $token)->assertStatus(422);
            $this->assertNull($user->fresh()->email_verified_at);
            $this->assertNull($record->fresh()->consumed_at);
        }
    }

    public function test_real_csrf_is_required_and_tokens_are_not_interchangeable_between_records(): void
    {
        $user = User::factory()->unverified()->create();
        [$record, $token] = $this->issue($user);
        $other = User::factory()->unverified()->create();
        [$otherRecord] = $this->issue($other);
        $this->complete($otherRecord, $token)->assertStatus(422);
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $this->withSession(['_token' => 'test-csrf-value']);
        $this->complete($record, $token)->assertStatus(419);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->complete($record, $token, ['_token' => 'test-csrf-value'])->assertRedirectToRoute('login');
        $this->assertGuest();
    }

    public function test_no_normal_guard_session_remember_login_or_login_event_can_admit_unverified_users(): void
    {
        $user = User::factory()->unverified()->create();
        $before = $user->fresh()->getAttributes();
        Volt::test('auth.login')->set('email', $user->email)->set('password', 'password')->set('remember', true)->call('login')->assertHasErrors('email');
        $this->assertGuest();
        $this->assertFalse(session()->has('login_web_'.sha1(SessionGuard::class)));
        $provider = Auth::getProvider();
        $this->assertNull($provider->retrieveById($user->id));
        $this->assertNull($provider->retrieveByToken($user->id, $user->remember_token));
        try {
            Auth::login($user);
            $this->fail('Direct normal login must also fail.');
        } catch (ValidationException) {
            $this->assertGuest();
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_unknown_verified_and_suspended_accounts_share_resend_status_and_no_send(): void
    {
        Notification::fake();
        $verified = User::factory()->create(['role' => 'admin']);
        $suspended = User::factory()->unverified()->create(['suspended_at' => now()]);
        foreach ([$verified->email, $suspended->email, 'unknown@taskbridge.test'] as $email) {
            $this->post(route('verification.send'), ['email' => $email])->assertRedirectToRoute('verification.notice')->assertSessionHas('status', VerifyExistingEmail::STATUS);
        }
        Notification::assertNothingSent();
        $this->assertDatabaseCount('email_verification_tokens', 0);
    }

    public function test_setup_and_existing_verification_tokens_cannot_be_substituted_between_purposes(): void
    {
        $user = User::factory()->unverified()->create();
        [$record, $verificationToken] = $this->issue($user);
        app(RegisterByEmail::class)->begin(['name' => 'New Person', 'email' => 'new-purpose@taskbridge.test', 'role' => 'client'], '192.0.2.94');
        $mail = Notification::sent(new AnonymousNotifiable, CompleteRegistration::class)->sole();
        parse_str(parse_url($mail->setupUrl, PHP_URL_FRAGMENT), $params);
        $pending = PendingRegistration::sole();
        $this->complete($record, $params['token'])->assertStatus(422);
        $this->post(route('registration.complete', $pending->id), ['email' => $pending->email, 'setup_token' => $verificationToken, 'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!'])->assertStatus(422);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($pending->fresh()->consumed_at);
        $this->assertDatabaseCount('users', 1);
        $this->assertGuest();
    }
}
