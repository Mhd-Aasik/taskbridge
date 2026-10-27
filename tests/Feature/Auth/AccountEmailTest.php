<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\SendAccountEmail;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Livewire\Volt\Volt;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\CapturedAccountEmail;
use Tests\TestCase;

class AccountEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_capture_parser_does_not_decode_hex_prefixed_mime_boundaries_as_body_content(): void
    {
        $mime = "Content-Type: multipart/alternative;\r\n boundary=ab1234\r\n\r\n--ab1234\r\nContent-Type: text/plain\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nHello=20TaskBridge\r\n--ab1234\r\nContent-Type: text/html\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('<p>Hello TaskBridge</p>')."\r\n--ab1234--\r\n";
        $parsed = CapturedAccountEmail::parse($mime);
        $this->assertSame('Hello TaskBridge', trim($parsed['plain']));
        $this->assertSame('<p>Hello TaskBridge</p>', trim($parsed['html']));
    }

    public function test_reset_responses_do_not_enumerate_addresses_and_requests_are_bounded(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'owner@taskbridge.test']);
        foreach ([$user->email, 'unknown@taskbridge.test', $user->email] as $email) {
            Volt::test('auth.forgot-password')->set('email', $email)->call('sendPasswordResetLink')->assertHasNoErrors()->assertSee(SendAccountEmail::RESET_STATUS);
        }
        Notification::assertSentToTimes($user, ResetPassword::class, 1);
        // IP limit applies before account lookup, independently of broker per-address throttling.
        for ($i = 0; $i < 6; $i++) {
            $account = User::factory()->create();
            app(SendAccountEmail::class)->reset($account->email, '192.0.2.40');
        }
        Notification::assertSentTimes(ResetPassword::class, 6);
    }

    public function test_verification_resend_throttle_is_shared_by_both_pages(): void
    {
        // During the explicitly staged production transition both legacy pages exist.
        config(['auth.require_verified_email' => false]);
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        Volt::test('auth.verify-email')->call('sendVerification')->assertHasNoErrors();
        Volt::test('settings.profile')->call('resendVerificationNotification')->assertHasErrors('verification');
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
        $this->travel(61)->seconds();
        Volt::test('settings.profile')->call('resendVerificationNotification')->assertHasNoErrors();
        Notification::assertSentToTimes($user, VerifyEmail::class, 2);
    }

    public function test_urls_ignore_request_host_and_verification_expiry_tampering_reuse_and_email_changes_are_safe(): void
    {
        config(['app.url' => 'https://trusted.taskbridge.test']);
        URL::setRequest(Request::create('https://attacker.invalid'));
        $user = User::factory()->unverified()->create(['role' => 'client']);
        $this->actingAs($user);
        $reset = (new ResetPassword('test-token'))->toMail($user);
        $url = (new VerifyEmail)->toMail($user)->actionUrl;
        $this->assertSame('trusted.taskbridge.test', parse_url($reset->actionUrl, PHP_URL_HOST));
        $this->assertSame('trusted.taskbridge.test', parse_url($url, PHP_URL_HOST));
        $this->get($url.'&tampered=1')->assertForbidden();
        $this->travel(61)->minutes();
        $this->get($url)->assertForbidden();
        $this->travelBack();
        $this->get($url)->assertRedirect();
        $verified = $user->fresh()->getRawOriginal('email_verified_at');
        $this->get($url)->assertRedirect();
        $this->assertSame($verified, $user->fresh()->getRawOriginal('email_verified_at'));
        Volt::test('settings.profile')->set('name', $user->name)->set('email', 'changed@taskbridge.test')->call('updateProfileInformation')->assertHasNoErrors();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->get($url)->assertForbidden();
        $this->get(route('client.dashboard'))->assertRedirectToRoute('verification.notice');
    }

    public function test_tampered_reset_token_cannot_change_account_state(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $before = $user->fresh()->getAttributes();
        Volt::test('auth.reset-password', ['token' => $token.'tampered'])->set('email', $user->email)->set('password', 'new-password')->set('password_confirmation', 'new-password')->call('resetPassword')->assertHasErrors('email');
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertTrue(Password::tokenExists($user, $token));
    }

    public function test_a_stale_authenticated_user_cannot_verify_an_address_changed_after_link_authorization(): void
    {
        $user = User::factory()->unverified()->create();
        $url = (new VerifyEmail)->toMail($user)->actionUrl;
        $this->actingAs($user);
        User::whereKey($user->id)->update(['email' => 'new-current@taskbridge.test']);
        $this->get($url)->assertForbidden();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_smtp_failures_are_safe_and_do_not_expose_accounts_or_transport_details(): void
    {
        $user = User::factory()->unverified()->create();
        $this->mock(Dispatcher::class)->shouldReceive('send')->andThrow(new TransportException('sensitive transport details'));
        Volt::test('auth.forgot-password')->set('email', $user->email)->call('sendPasswordResetLink')->assertHasNoErrors()->assertSee(SendAccountEmail::RESET_STATUS)->assertDontSee('sensitive transport details');
        $this->actingAs($user);
        Volt::test('auth.verify-email')->call('sendVerification')->assertHasErrors('verification')->assertDontSee('sensitive transport details');
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame(0, $user->fresh()->access_version);
    }
}
