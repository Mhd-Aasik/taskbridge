<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\RegisterByEmail;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Notifications\CompleteRegistration;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\EnforcedCsrfToken;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function begin(string $role = 'client', string $email = 'new@taskbridge.test'): array
    {
        Notification::fake();
        app(RegisterByEmail::class)->begin(['name' => 'New Person', 'email' => $email, 'role' => $role], '192.0.2.1');
        $notification = Notification::sent(new AnonymousNotifiable, CompleteRegistration::class)->last();
        parse_str(parse_url($notification->setupUrl, PHP_URL_FRAGMENT), $parameters);
        $pending = PendingRegistration::where('email', strtolower($email))->firstOrFail();

        return [$pending, $parameters['token'], $notification->setupUrl];
    }

    private function input(PendingRegistration $pending, string $token): array
    {
        return ['email' => $pending->email, 'setup_token' => $token, 'password' => 'StrongPassword123!', 'password_confirmation' => 'StrongPassword123!'];
    }

    public function test_registration_collects_identity_only_and_creates_no_account_or_session(): void
    {
        Notification::fake();
        $this->get('/register')->assertOk()->assertSee('Account type')->assertDontSee('name="password"', false);
        Volt::test('auth.register')->set('name', 'New Person')->set('email', 'new@taskbridge.test')->set('role', 'client')->call('register')->assertHasNoErrors()->assertSee(RegisterByEmail::STATUS);
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('pending_registrations', 1);
        Notification::assertSentOnDemandTimes(CompleteRegistration::class, 1);
    }

    public function test_both_public_roles_complete_atomically_verified_and_then_use_normal_login(): void
    {
        foreach (['client', 'freelancer'] as $index => $role) {
            [$pending, $token, $url] = $this->begin($role, $role.'@taskbridge.test');
            $this->assertSame(hash('sha256', $token), $pending->token_hash);
            $this->assertNotSame($token, $pending->token_hash);
            $this->assertStringNotContainsString($token, parse_url($url, PHP_URL_PATH));
            $this->assertEmpty(parse_url($url, PHP_URL_QUERY));
            $this->post(route('registration.complete', $pending->id), $this->input($pending, $token))->assertRedirectToRoute('login');
            $this->assertGuest();
            $user = User::where('email', $pending->email)->firstOrFail();
            $this->assertSame($role, $user->role);
            $this->assertNotNull($user->email_verified_at);
            $this->assertTrue(Hash::check('StrongPassword123!', $user->password));
            $this->assertNotNull($pending->fresh()->consumed_at);
            Volt::test('auth.login')->set('email', $user->email)->set('password', 'StrongPassword123!')->call('login')->assertHasNoErrors()->assertRedirect('/dashboard');
            $this->get('/dashboard')->assertRedirectToRoute($role.'.dashboard');
            auth()->logout();
            session()->flush();
        }
    }

    public function test_get_link_and_prefetch_do_not_consume_or_verify_anything(): void
    {
        [$pending, $token] = $this->begin();
        $before = $pending->getAttributes();
        $this->get(route('registration.setup', $pending->id))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertDontSee($token);
        $this->get(route('registration.setup', $pending->id), ['Purpose' => 'prefetch'])->assertOk();
        $this->assertSame($before, $pending->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 0);
    }

    public function test_expired_tampered_wrong_email_and_replayed_tokens_are_rejected(): void
    {
        [$pending, $token] = $this->begin();
        foreach ([['setup_token' => str_repeat('x', 64)], ['email' => 'another@taskbridge.test']] as $changes) {
            $this->post(route('registration.complete', $pending->id), array_replace($this->input($pending, $token), $changes))->assertStatus(422);
            $this->assertNull($pending->fresh()->consumed_at);
        }
        $this->travel(61)->minutes();
        $this->post(route('registration.complete', $pending->id), $this->input($pending, $token))->assertStatus(422);
        $this->assertDatabaseCount('users', 0);
        $this->travelBack();
        $this->post(route('registration.complete', $pending->id), $this->input($pending, $token))->assertRedirectToRoute('login');
        $before = User::first()->getAttributes();
        $this->post(route('registration.complete', $pending->id), $this->input($pending, $token))->assertStatus(422);
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, User::first()->getAttributes());
    }

    public function test_token_substitution_and_identity_or_role_tampering_do_not_create_users(): void
    {
        [$a, $tokenA] = $this->begin('client', 'one@taskbridge.test');
        [$b, $tokenB] = $this->begin('freelancer', 'two@taskbridge.test');
        $this->post(route('registration.complete', $b->id), $this->input($b, $tokenA))->assertStatus(422);
        foreach (['role' => 'admin', 'name' => 'Other Person'] as $key => $value) {
            $this->post(route('registration.complete', $a->id), $this->input($a, $tokenA) + [$key => $value])->assertStatus(422);
        }
        $a->forceFill(['role' => 'admin'])->save();
        $this->post(route('registration.complete', $a->id), $this->input($a, $tokenA))->assertStatus(422);
        $this->assertDatabaseCount('users', 0);
        $this->assertNull($b->fresh()->consumed_at);
    }

    public function test_public_admin_registration_and_initial_password_are_forbidden(): void
    {
        Notification::fake();
        Volt::test('auth.register')->set('name', 'Admin Attempt')->set('email', 'admin@taskbridge.test')->set('role', 'admin')->call('register')->assertHasErrors('role');
        try {
            app(RegisterByEmail::class)->begin(['name' => 'Person', 'email' => 'person@taskbridge.test', 'role' => 'client', 'password' => 'Premature123!'], '192.0.2.2');
            $this->fail('Initial password must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('password', $e->errors());
        }
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('pending_registrations', 0);
        Notification::assertNothingSent();
    }

    public function test_resend_rotates_token_and_invalidates_old_link_with_same_bound_identity(): void
    {
        [$pending, $old] = $this->begin();
        $this->travel(61)->seconds();
        app(RegisterByEmail::class)->resend($pending->email, '192.0.2.1');
        $notification = Notification::sent(new AnonymousNotifiable, CompleteRegistration::class)->last();
        parse_str(parse_url($notification->setupUrl, PHP_URL_FRAGMENT), $params);
        $this->assertNotSame(hash('sha256', $old), $pending->fresh()->token_hash);
        $this->post(route('registration.complete', $pending->id), $this->input($pending, $old))->assertStatus(422);
        $this->post(route('registration.complete', $pending->id), $this->input($pending, $params['token']))->assertRedirectToRoute('login');
    }

    public function test_registration_and_resend_share_throttles_before_lookup(): void
    {
        [$pending] = $this->begin();
        for ($i = 0; $i < 7; $i++) {
            app(RegisterByEmail::class)->resend($pending->email, '192.0.2.1');
        }
        Notification::assertSentOnDemandTimes(CompleteRegistration::class, 1);
        for ($i = 0; $i < 8; $i++) {
            app(RegisterByEmail::class)->begin(['name' => 'Person', 'email' => 'ip-limit-'.$i.'@taskbridge.test', 'role' => 'client'], '192.0.2.50');
        }
        Notification::assertSentOnDemandTimes(CompleteRegistration::class, 6);
    }

    public function test_existing_user_and_password_are_never_overwritten(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['email' => 'existing@taskbridge.test', 'role' => 'admin']);
        $before = $user->fresh()->getAttributes();
        app(RegisterByEmail::class)->begin(['name' => 'Attacker', 'email' => strtoupper($user->email), 'role' => 'client'], '192.0.2.9');
        app(RegisterByEmail::class)->resend($user->email, '192.0.2.10');
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('pending_registrations', 0);
        Notification::assertNothingSent();
    }

    public function test_setup_cannot_overwrite_an_account_created_after_the_link_was_issued(): void
    {
        [$pending, $token] = $this->begin();
        $existing = User::factory()->create(['email' => $pending->email]);
        $before = $existing->fresh()->getAttributes();
        $this->post(route('registration.complete', $pending->id), $this->input($pending, $token))->assertStatus(422);
        $this->assertSame($before, $existing->fresh()->getAttributes());
        $this->assertNull($pending->fresh()->consumed_at);
    }

    public function test_smtp_failure_rolls_back_pending_registration_and_redacts_details(): void
    {
        $this->mock(Dispatcher::class)->shouldReceive('send')->andThrow(new TransportException('SECRET-TRANSPORT-DETAILS'));
        Volt::test('auth.register')->set('name', 'Person')->set('email', 'failure@taskbridge.test')->call('register')->assertHasErrors('email')->assertDontSee('SECRET-TRANSPORT-DETAILS');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('pending_registrations', 0);
    }

    public function test_failed_resend_preserves_the_previously_delivered_link(): void
    {
        [$pending, $token] = $this->begin();
        $before = $pending->getAttributes();
        $this->travel(61)->seconds();
        $this->mock(Dispatcher::class)->shouldReceive('send')->andThrow(new TransportException('SECRET-TRANSPORT-DETAILS'));
        try {
            app(RegisterByEmail::class)->resend($pending->email, '192.0.2.1');
            $this->fail('Delivery must fail safely.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('email', $e->errors());
        }
        $this->assertSame($before, $pending->fresh()->getAttributes());
        app(RegisterByEmail::class)->complete($pending->id, $this->input($pending, $token));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_trusted_setup_url_ignores_request_host_and_keeps_token_out_of_request_path(): void
    {
        config(['app.url' => 'https://trusted.taskbridge.test']);
        URL::setRequest(Request::create('https://attacker.invalid'));
        [$pending, $token, $url] = $this->begin();
        $this->assertSame('trusted.taskbridge.test', parse_url($url, PHP_URL_HOST));
        $this->assertStringNotContainsString($token, explode('#', $url)[0]);
        $this->assertStringContainsString('#token=', $url);
    }

    public function test_real_csrf_middleware_rejects_missing_token_and_accepts_only_the_validated_post(): void
    {
        [$pending, $token] = $this->begin();
        $this->app->bind(ValidateCsrfToken::class, EnforcedCsrfToken::class);
        $this->withSession(['_token' => 'synthetic-csrf-session-token']);
        $url = route('registration.complete', $pending->id);
        $this->post($url, $this->input($pending, $token))->assertStatus(419);
        $this->assertDatabaseCount('users', 0);
        $this->assertNull($pending->fresh()->consumed_at);
        $this->post($url, $this->input($pending, $token) + ['_token' => 'wrong-token'])->assertStatus(419);
        $this->post($url, $this->input($pending, $token) + ['_token' => 'synthetic-csrf-session-token'])->assertRedirectToRoute('login');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_password_does_not_consume_or_flash_secrets_and_can_be_corrected(): void
    {
        [$pending, $token] = $this->begin();
        foreach ([['password_confirmation' => 'Mismatch123!'], ['password' => 'weak', 'password_confirmation' => 'weak']] as $invalid) {
            $this->post(route('registration.complete', $pending->id), array_replace($this->input($pending, $token), $invalid))->assertStatus(422);
            $this->assertNull($pending->fresh()->consumed_at);
            $this->assertArrayNotHasKey('setup_token', session()->getOldInput());
            $this->assertArrayNotHasKey('password', session()->getOldInput());
            $this->assertArrayNotHasKey('password_confirmation', session()->getOldInput());
        }
        $this->post(route('registration.complete', $pending->id), $this->input($pending, $token))->assertRedirectToRoute('login');
    }

    public function test_log_and_failover_mailers_cannot_record_setup_or_reset_tokens(): void
    {
        foreach (['log', 'failover'] as $mailer) {
            config(['mail.default' => $mailer]);
            try {
                app(RegisterByEmail::class)->begin(['name' => 'Person', 'email' => $mailer.'@taskbridge.test', 'role' => 'client'], '192.0.2.123');
                $this->fail('Account secrets must never enter a log mailer.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('email', $e->errors());
            }
        }
        $this->assertDatabaseCount('pending_registrations', 0);
    }

    public function test_malformed_completion_fields_are_validation_errors_without_consuming_the_token(): void
    {
        [$pending, $token] = $this->begin();
        foreach (['email', 'setup_token', 'password'] as $field) {
            $this->post(route('registration.complete', $pending->id), array_replace($this->input($pending, $token), [$field => ['invalid-array']]))->assertStatus(422);
            $this->assertNull($pending->fresh()->consumed_at);
        }
        $this->assertDatabaseCount('users', 0);
    }
}
