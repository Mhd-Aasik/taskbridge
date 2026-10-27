<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\RegisterByEmail;
use App\Actions\Accounts\SendAccountEmail;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Support\AccountAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Symfony\Component\Process\Process;
use Tests\Support\CapturedAccountEmail;
use Tests\TestCase;

class AccountSmtpTest extends TestCase
{
    use RefreshDatabase;

    public function test_branded_setup_mail_is_captured_locally_and_creates_no_account_before_completion(): void
    {
        $directory = storage_path('framework/testing/smtp-'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        $server = new Process([PHP_BINARY, base_path('tests/Support/smtp-capture.php'), $directory]);
        $server->start();
        try {
            $deadline = microtime(true) + 10;
            while (! is_file($directory.'/ready')) {
                $this->assertTrue($server->isRunning() && microtime(true) < $deadline);
                usleep(10000);
            }
            config(['app.url' => 'http://trusted.taskbridge.test', 'mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => (int) file_get_contents($directory.'/ready'), 'scheme' => 'smtp', 'timeout' => 2, 'auto_tls' => false, 'require_tls' => false], 'mail.from' => ['address' => 'security@taskbridge.test', 'name' => 'TaskBridge']]);
            Mail::purge('smtp');
            app(RegisterByEmail::class)->begin(['name' => 'Captured Identity', 'email' => 'new@taskbridge.test', 'role' => 'freelancer'], '192.0.2.40');
            $files = glob($directory.'/*.eml');
            $this->assertCount(1, $files);
            $raw = file_get_contents($files[0]);
            $parsed = CapturedAccountEmail::parse($raw);
            foreach (['plain', 'html'] as $part) {
                foreach (['TaskBridge', '60 minutes', 'If you did not request', 'copy and paste'] as $text) {
                    $this->assertStringContainsString($text, $parsed[$part]);
                }
            }
            $this->assertStringContainsString('Subject: Finish setting up your TaskBridge account', $raw);
            $this->assertStringContainsString('To: new@taskbridge.test', $raw);
            $this->assertSame(1, preg_match('/href="(http:\/\/trusted\.taskbridge\.test\/register\/setup\/[^"]+)"/', $parsed['html'], $matches));
            $url = html_entity_decode($matches[1]);
            parse_str(parse_url($url, PHP_URL_FRAGMENT), $parameters);
            $this->assertEmpty(parse_url($url, PHP_URL_QUERY));
            $this->assertStringContainsString($url, $parsed['plain']);
            $this->get(explode('#', $url)[0])->assertOk();
            $this->assertDatabaseCount('users', 0);
            $pending = PendingRegistration::sole();
            $this->post(route('registration.complete', $pending->id), ['email' => $pending->email, 'setup_token' => $parameters['token'], 'password' => 'CapturedPassword123!', 'password_confirmation' => 'CapturedPassword123!'])->assertRedirectToRoute('login');
            $this->assertTrue(User::sole()->hasVerifiedEmail());
            $this->assertCount(1, glob($directory.'/*.eml'));
        } finally {
            $server->stop(1);
            Mail::purge('smtp');
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public function test_actual_loopback_smtp_multipart_messages_and_captured_links_work(): void
    {
        $directory = storage_path('framework/testing/smtp-'.bin2hex(random_bytes(8)));
        mkdir($directory, 0777, true);
        $server = new Process([PHP_BINARY, base_path('tests/Support/smtp-capture.php'), $directory]);
        $server->start();
        try {
            $until = microtime(true) + 10;
            while (! is_file($directory.'/ready')) {
                $this->assertTrue($server->isRunning() && microtime(true) < $until, 'Loopback SMTP did not become ready.');
                usleep(10000);
            }
            config(['app.url' => 'http://trusted.taskbridge.test', 'mail.default' => 'smtp', 'mail.mailers.smtp' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => (int) file_get_contents($directory.'/ready'), 'scheme' => 'smtp', 'timeout' => 2, 'auto_tls' => false, 'require_tls' => false], 'mail.from' => ['address' => 'security@taskbridge.test', 'name' => 'TaskBridge']]);
            Mail::purge('smtp');
            $user = User::factory()->unverified()->create(['email' => 'recipient@taskbridge.test', 'role' => 'freelancer']);
            Volt::test('auth.forgot-password')->set('email', $user->email)->call('sendPasswordResetLink')->assertHasNoErrors();
            $this->actingAs($user);
            Volt::test('auth.verify-email')->call('sendVerification')->assertHasNoErrors();
            $messages = glob($directory.'/*.eml');
            $this->assertCount(2, $messages);
            $urls = [];
            foreach ($messages as $index => $path) {
                $raw = file_get_contents($path);
                $parsed = CapturedAccountEmail::parse($raw);
                $plain = $parsed['plain'];
                $html = $parsed['html'];
                $mime = $raw.$plain.$html;
                foreach ([$plain, $html] as $body) {
                    foreach (['TaskBridge', '60 minutes', 'If you did not request', 'copy and paste'] as $text) {
                        $this->assertTrue(str_contains($body, $text), 'Multipart body content missing: '.$text);
                    }
                }
                $this->assertTrue(str_contains($mime, $index === 0 ? 'Subject: Reset your TaskBridge password' : 'Subject: Verify your TaskBridge email'), 'Captured subject mismatch.');
                foreach (['From: TaskBridge <security@taskbridge.test>', 'To: recipient@taskbridge.test', 'text/html', 'text/plain', 'TaskBridge', '60 minutes', 'If you did not request', 'copy and paste'] as $text) {
                    $this->assertTrue(str_contains($mime, $text), 'Captured MIME content check failed: '.$text);
                }
                $this->assertFalse(str_contains($mime, 'attacker.invalid'));
                $this->assertTrue(preg_match('/href="(http:\/\/trusted\.taskbridge\.test\/(?:reset-password|verify-email)[^"]+)"/', $html, $matches) === 1, 'Captured action URL missing.');
                $url = html_entity_decode($matches[1]);
                $this->assertTrue(str_contains($plain, $url), 'Plain-text fallback URL is missing or incorrectly escaped.');
                $urls[] = $url;
            }
            $reset = $urls[0];
            $verification = $urls[1];
            $this->get($verification)->assertRedirect();
            $this->assertTrue($user->fresh()->hasVerifiedEmail());
            $this->get(route('dashboard'))->assertRedirectToRoute('freelancer.dashboard');
            auth()->logout();
            parse_str(parse_url($reset, PHP_URL_FRAGMENT), $parameters);
            $this->get(explode('#', $reset)[0])->assertOk();
            $this->post(route('password.update'), ['token' => $parameters['token'], 'email' => $parameters['email'], 'password' => 'smtp-tested-password', 'password_confirmation' => 'smtp-tested-password'])->assertRedirectToRoute('login');
            $this->assertSame(1, $user->fresh()->access_version);
            // Real transport failure, not a fake dispatcher, is also handled safely.
            $server->stop(1);
            Mail::purge('smtp');
            $this->travel(61)->seconds();
            $user = $user->fresh();
            $user->forceFill(['email_verified_at' => null])->save();
            $this->actingAs($user);
            session()->put(AccountAccess::SESSION_KEY, ['id' => $user->id, 'version' => $user->access_version]);
            Volt::test('auth.verify-email')->call('sendVerification')->assertHasErrors('verification');
            auth()->logout();
            $other = User::factory()->create(['email' => 'another@taskbridge.test']);
            Volt::test('auth.forgot-password')->set('email', $other->email)->call('sendPasswordResetLink')->assertHasNoErrors()->assertSee(SendAccountEmail::RESET_STATUS);
        } finally {
            $server->stop(1);
            Mail::purge('smtp');
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }rmdir($directory);
        }
    }
}
