<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VerificationReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_cannot_enable_or_verify_an_unverified_admin(): void
    {
        $admin = User::factory()->unverified()->create(['role' => 'admin']);
        $before = $admin->fresh()->getAttributes();
        $this->artisan('taskbridge:verification-readiness', ['--admin-email' => $admin->email])->assertFailed();
        $this->assertSame($before, $admin->fresh()->getAttributes());
    }

    public function test_capture_mail_or_missing_credentials_cannot_pass_production_release_gate(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        config(['app.debug' => false, 'app.url' => 'https://trusted.taskbridge.test', 'mail.default' => 'array']);
        $this->artisan('taskbridge:verification-readiness', ['--admin-email' => $admin->email])->assertFailed();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.username' => null]);
        $this->artisan('taskbridge:verification-readiness', ['--admin-email' => $admin->email])->assertFailed();
        config(['mail.mailers.smtp.username' => 'synthetic@taskbridge.test', 'mail.mailers.smtp.password' => 'synthetic-test-only', 'mail.mailers.smtp.require_tls' => true]);
        $this->artisan('taskbridge:verification-readiness', ['--admin-email' => $admin->email, '--enforced' => true])->assertSuccessful();
    }
}
